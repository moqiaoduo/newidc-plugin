<?php

namespace NewIDC\Plugin;

use Illuminate\Support\Arr;

/**
 * 插件将遵循Laravel扩展包开发方法开发
 * 插件通过调用register方法注册hook
 * 程序则在指定位置触发hook
 */
class Manager
{
    /**
     * 插件列表
     *
     * @var array
     */
    private $plugins = [];

    private $ena_plugins;

    private $hooks = [];

    public function __construct()
    {
        $this->ena_plugins=json_decode(getOption('ena_plugins'),true)?:[];
    }

    /**
     * 不是所有的插件都能手动开关
     * Server插件只要存在即开启
     * 插件在boot方法务必执行一下，否则无法正常识别
     *
     * @param Plugin $plugin 插件对象
     */
    public function register($plugin)
    {
        // 传入plugin对象，自动注册hook以及加入插件列表
        $this->plugins[]=$info=$plugin->info();
        if ($plugin instanceof Server || ($ena=$this->checkEnable($info['slug']))) {
            if (!($ena??false)) // 如果没有加入启用列表，则加入
                $this->ena_plugins[]=$info['slug'];
            foreach ((array) $plugin->hook() as $hook)
                $this->hooks[$hook['hook']]=['plugin'=>$plugin,'func'=>$hook['func']];
        }
    }

    /**
     * 列出所有插件
     *
     * @return array
     */
    public function pList()
    {
        return $this->plugins;
    }

    public function checkEnable($slug)
    {
        return in_array($slug,$this->ena_plugins);
    }

    public function trigger($hook, $default=null, $data=null, $last=false, $returnArray=false)
    {
        $hasRun=false;$return=null;
        if ($returnArray) $return=[];
        if ($last) {
            $hook=Arr::last($this->hooks[$hook]);
            if (is_callable([$hook['plugin'],$hook['func']])) {
                $return=$hook['plugin']->$hook['func']($data);
                if ($returnArray) $return=[$return];
                $hasRun=true;
            }
        } else {
            foreach ((array) $this->hooks[$hook] as $hook) {
                if (is_callable([$hook['plugin'],$hook['func']])) {
                    $result=$hook['plugin']->$hook['func']($data);
                    if ($returnArray) $return[]=$result;
                    else $return.=$result;
                    $hasRun=true;
                }
            }
        }
        if (!$hasRun && is_callable($default)) {
            $return=$default($data);
            if ($returnArray) $return=[$return];
        }
        return $return;
    }
}