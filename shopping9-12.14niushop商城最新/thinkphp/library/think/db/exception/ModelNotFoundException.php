<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed (  )
// +----------------------------------------------------------------------
// | 
// +----------------------------------------------------------------------

namespace think\db\exception;

use think\exception\DbException;

class ModelNotFoundException extends DbException 
{
    protected $model;

    /**
     * 构造方法
     * @param string $message
     * @param string $model
     */
    public function __construct($message, $model = '', Array $config = [])
    {
        $this->message  = $message;
        $this->model    = $model;

        $this->setData('Database Config', $config);
    }

    /**
     * 获取模型类名
     * @access public
     * @return string
     */
    public function getModel()
    {
        return $this->model;
    }

}
