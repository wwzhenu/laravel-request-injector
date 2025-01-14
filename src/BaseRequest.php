<?php
namespace Marin\LaravelRequestInjector;

use Exception;
use ReflectionException;
use ReflectionProperty;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Marin\LaravelRequestInjector\Exceptions\BusinessCheckFailException;
use Marin\LaravelRequestInjector\Exceptions\CanNotEmptyException;
use Marin\LaravelRequestInjector\Exceptions\MissParamException;
use Marin\LaravelRequestInjector\Exceptions\TypeErrorException;

/**
 * Class Request
 * @package App\Models\Request
 * 获取类属性，从其注释中获取类型及required
 * 从request中获取相关参数，赋值类属性
 *
 * 默认从请求参数中获取与变量名称相同的参数
 * 若未获取到，则尝试获取变量转为下划线格式的参数
 * 若变量格式为数组，可使用@itemType 标识数组中的元素类型
 * 若在注释中使用了 @requestVar ，则使用标识中的参数名称
 * 若在注释中使用了 @afterInitCallBack ，则在赋值完成后调用对应的方法，将返回值赋值给变量，务必使用对象中存在的方法，否则不会执行;
 *      适用于对参数进行二次处理的情况；
 *      适用于不需要定义在其后变量的情况
 * 若在注释中使用了 @afterObjInitCallback ，则在所有变量赋值完成后调用对应的方法，将返回值赋值给变量，务必使用对象中存在的方法，否则不会执行；
 *      若多个变量均定义了该标识，则按照变量定义的顺序执行；
 *      适用于对参数进行二次处理的情况；
 *      适用于需要定义在其后变量的情况
 *
 * 不支持多维数组
 */
abstract class BaseRequest
{
    public array $requiredVars = [];
    public array $notEmptyVars = [];

    /**
     * @param array $params
     * @throws ReflectionException
     * @throws Exception
     */
    public function __construct(array $params = [])
    {
        empty($params) && $params = (app(Request::class)->all());
        if (empty($params)){
            return;
        }
        $className = get_class($this);
        $vars = get_class_vars($className);
        $vars = array_keys($vars);
        $afterObjInitCallbackArray = [];
        foreach ($vars as $var) {
            $prop = new ReflectionProperty($className, $var);
            $doc = $prop->getDocComment();
            if ($callBack = $this->beforeInitCallBack($doc))
            {
                if (method_exists($this,$callBack))
                {
                    try {
                        $this->{$var} = call_user_func_array([$this,$callBack],[]);
                    }catch (\Exception $e){
                        throw new BusinessCheckFailException($e->getMessage());
                    }

                }
            }
            // 获取变量对应的请求参数名称
            // 1.默认为变量名称
            $requestVar = $var;
            // 2.若在请求中不存在，则尝试获取下划线格式的参数
            if (!Arr::exists($params,$requestVar)) {
                $requestVar = Str::snake($requestVar);
            }
            // 3.若在注释中使用了 @requestVar ,则使用标识中的参数名称
            if ($doc){
                $requestVar = $this->getRequestVarName($doc,$requestVar);
            }
            // 若在请求中不存在且变量为必须参数，则抛出异常
            // 若在请求中不存在且变量不为必须参数，则跳过
            if (!Arr::exists($params,$requestVar)) {
                if ($this->isRequired($doc,$requestVar) || $this->notEmpty($doc,$requestVar)){
                    throw new MissParamException("missing {$requestVar}");
                }else{
                    continue;
                }
            }
            if ($this->notEmpty($doc,$requestVar) && empty($params[$requestVar])){
                throw new CanNotEmptyException("{$requestVar} can not be empty");
            }

            /** @phpstan-ignore-next-line */
            $propType = $prop->getType()->getName();
            $data = Arr::get($params,$requestVar);
            // 若为标量 直接赋值即可
            if ($this->isScalar($propType)){
                $tmpData = Arr::get($params,$requestVar, $this->{$var});
                if (!is_null($tmpData) && !is_scalar($tmpData)){
                    throw new MissParamException("{$requestVar} type error");
                }
                settype($tmpData,$propType);
                $this->{$var} = $tmpData;
            }elseif ($propType == 'array'){
                // 若为数组，尝试获取item类型，默认为string
                $itemType = $this->getItemType($doc);
                if (!is_array($data)){
                    throw new TypeErrorException("{$requestVar} type error");
                }
                if ($this->isScalar($itemType)){
                    foreach ($data as $v){
                        if (!is_scalar($v)){
                            throw new TypeErrorException("{$requestVar} type error");
                        }
                        settype($v,$itemType);
                        $this->{$var}[] = $v;
                    }
                }else {
                    if (!class_exists($itemType)){
                        throw new TypeErrorException("{$requestVar} type error");
                    }
                    foreach ($data as $v) {
                        if (!is_array($v)) {
                            throw new TypeErrorException("{$requestVar} type error");
                        }
                        $this->{$var}[] = new $itemType($v);
                    }
                }
            }else{
                if (!is_array($data)){
                    throw new TypeErrorException("{$requestVar} type error");
                }
                $this->{$var} = new $propType($data);
            }
            if ($callBack = $this->afterInitCallBack($doc))
            {
                if (method_exists($this,$callBack))
                {
                    try {
                        $this->{$var} = call_user_func_array([$this,$callBack],[]);
                    }catch (\Exception $e){
                        throw new Exception($e->getMessage());
                    }

                }
            }
            if ($callBack = $this->afterObjInitCallback($doc))
            {
                if (method_exists($this,$callBack))
                {
                    $afterObjInitCallbackArray[$var] = $callBack;

                }
            }

        }
        foreach ($afterObjInitCallbackArray as $var => $callBack){
            $this->{$var} = call_user_func_array([$this,$callBack],[]);
        }
        $this->__init();
    }

    /**
     * 初始化完成后调用的方法
     */
    public function __init(){}

    /**
     * @param $string
     * @return bool
     * 改属性是否为必须参数
     */
    private function isRequired($string,$varName): bool
    {
        if (strpos($string, '@required') || in_array($varName,$this->requiredVars)) {
            return true;
        }
        return false;
    }

    private function notEmpty($doc,$varName): bool
    {
        if (strpos($doc, '@notEmpty') || in_array($varName,$this->notEmptyVars)) {
            return true;
        }
        return false;
    }

    private function beforeInitCallBack($doc):string
    {
        if (str_contains($doc, '@beforeInitCallBack')) {
            $tmp = substr($doc,strpos($doc, '@beforeInitCallBack') + 19);
            return trim(substr($tmp,0,strpos($tmp, "\n")));
        }
        return '';
    }

    /**
     * @param $doc
     * @return string
     */
    private function afterInitCallBack($doc):string
    {
        if (str_contains($doc, '@afterInitCallBack')) {
            $tmp = substr($doc,strpos($doc, '@afterInitCallBack') + 18);
            return trim(substr($tmp,0,strpos($tmp, "\n")));
        }
        return '';
    }

    private function afterObjInitCallback($doc): string
    {
        if (str_contains($doc, '@afterObjInitCallback')) {
            $tmp = substr($doc,strpos($doc, '@afterObjInitCallback') + 21);
            return trim(substr($tmp,0,strpos($tmp, "\n")));
        }
        return '';
    }

    private function getRequestVarName($doc,$var)
    {
        if (str_contains($doc, '@requestVar')) {
            $tmp = substr($doc,strpos($doc, '@requestVar') + 11);
            return trim(substr($tmp,0,strpos($tmp, "\n")));
        }
        return $var;
    }

    private function getItemType($doc): string
    {
        if (str_contains($doc, '@itemType')) {
            $tmp = substr($doc,strpos($doc, '@itemType') + 9);
            return trim(substr($tmp,0,strpos($tmp, "\n")));
        }
        return 'string';
    }

    private function isScalar($type): bool
    {
        return in_array($type, ['int', 'string', 'float', 'bool']);
    }

}
