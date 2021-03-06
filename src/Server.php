<?php

namespace NewIDC\Plugin;

use App\Models\Product;
use App\Models\Service;
use App\Events\ServiceActivate;
use App\Events\ServiceSuspend;
use App\Events\ServiceTerminate;
use App\Events\ServiceUnsuspend;

abstract class Server implements Plugin
{
    /**
     * 插件名称
     *
     * @var string
     */
    protected $name;

    /**
     * 插件composer名称
     * 非必须，使用该名称可以检索到release版本
     * 若未检索到则调用插件设定的插件版本
     *
     * @var string
     */
    protected $composer;

    /**
     * 插件版本
     * composer和version只用填写一个
     * composer检测版本会覆盖version设置
     *
     * @var string
     */
    protected $version;

    /**
     * 插件说明
     *
     * @var string
     */
    protected $description;

    /**
     * 服务模型
     *
     * @var Service
     */
    protected $service;

    /**
     * 服务器模型
     *
     * @var \App\Models\Server
     */
    protected $server;

    /**
     * 产品模型
     *
     * @var Product
     */
    protected $product;

    /**
     * 服务激活时的操作
     *
     * 所有操作均需要返回：
     * ['code'=>状态码(0为成功标准),'message'=>错误信息]
     * 状态码和错误信息主要用于调试
     * 其他返回值不接受
     * 如果需要修改extra字段，直接更新就行了
     *
     * @return array
     */
    abstract public function activate();

    /**
     * 服务暂停时的操作
     *
     * @return array
     */
    abstract public function suspend();

    /**
     * 服务解除暂停时的操作
     *
     * @return array
     */
    abstract public function unsuspend();

    /**
     * 服务销毁时的操作
     *
     * @return array
     */
    abstract public function terminate();

    /**
     * 修改密码时的操作
     *
     * @param string $password
     * @return array
     */
    abstract public function changePassword($password);

    /**
     * 升降级操作
     *
     * @return array
     */
    abstract public function upgradeDowngrade();

    /**
     * 用户区登录
     *
     * @return string
     */
    public function userLogin()
    {
        return "None";
    }

    /**
     * 管理区登录
     *
     * @return string
     */
    public function adminLogin()
    {
        return "None";
    }

    /**
     * 其他设置
     *
     * @return array
     */
    static public function otherConfig()
    {
        return [];
    }

    /**
     * 产品配置
     *
     * @return array
     */
    static public function productConfig()
    {
        return [];
    }

    /**
     * 用户前端设置
     *
     * @return array
     */
    static public function userConfig()
    {
        return [];
    }

    /**
     * 升降级产品设置
     *
     * @return array
     */
    static public function upgradeDowngradeConfig()
    {
        return [];
    }

    /**
     * 升降级前端设置
     *
     * @return array
     */
    static public function userUpgradeDowngradeConfig()
    {
        return [];
    }

    /**
     * 域名设置
     *
     * @return array
     */
    static public function domainConfig()
    {
        return [];
    }

    /**
     * 默认端口
     *
     * @return int
     */
    protected function defaultPort()
    {
        return 2086;
    }

    /**
     * 为了不影响插件注册，不采用构造函数来初始化数据
     *
     * @param $product
     * @param $service
     * @param $server
     */
    public function init(?Product $product, ?Service $service, ?\App\Models\Server $server)
    {
        $this->product = $product;
        $this->service = $service;
        $this->server = $server;
    }

    /**
     * 使用属性来定义插件信息
     * 当前版本使用类名（带命名空间）作为唯一识别符
     *
     * @return array
     */
    public function info(): array
    {
        $data = [
            'name' => $this->name,
            'description' => $this->description
        ];
        if (!empty($this->composer)) {
            $data['composer'] = $this->composer;
        } else {
            $data['version'] = $this->version;
        }
        return $data;
    }

    /**
     * 即使没有注册hook，只要手动注册了，都会出现在插件列表，
     * 只是无法被作为钩子调用，但是NewIDC内部有一套其他的处理程序
     * 如果有特殊需求也可以注册钩子
     *
     * @return array
     */
    public function hook(): array
    {
        return [];
    }

    /**
     * 获取主机名/IP
     *
     * @param bool $api //是否为api方式获取host，0.5.7添加
     * @return string
     */
    protected function getHost($api = true)
    {
        return $api && $this->server->api_access_address == 'ip' || empty($this->server->hostname) ?
            $this->server->ip :
            $this->server->hostname;
    }

    /**
     * 获取端口
     *
     * @return int
     */
    protected function getPort()
    {
        return $this->server->port ?: $this->defaultPort();
    }

    /**
     * 服务信息（显示在服务详情中）
     * 0.3.3开始，非必须方法
     *
     * @return array
     */
    public function serviceInfo()
    {
        return [];
    }

    /**
     * 后端服务设置项
     * 仅限编辑服务时使用，会从数据库自动填充extra数据
     * 目前系统已占用suspend_reason，请勿使用
     *
     * @return array
     */
    public function serviceConfig()
    {
        return [];
    }

    /**
     * 执行插件命令
     *
     * @param string $command
     * @param mixed $payload
     * @return array
     */
    public function command($command, $payload = null)
    {
        $service = $this->service;
        switch ($command) {
            case 'create':
                $result = $this->activate();
                if ($result['code'] === 0) {
                    $service->update(['status' => 'active']);
                    event(new ServiceActivate($service));
                }
                break;
            case 'suspend':
                $result = $this->suspend();
                if ($result['code'] === 0) {
                    $service->status = 'suspended';
                    if (empty($payload['suspend_reason']))
                        $reason = __('service.expire_suspend');
                    else
                        $reason = $payload['suspend_reason'];
                    $extra = $service->extra;
                    $extra['suspend_reason'] = $reason;
                    $service->extra = $extra;
                    $service->save();
                    event(new ServiceSuspend($service, isset($payload['mail'])));
                }
                break;
            case 'unsuspend':
                $result = $this->unsuspend();
                if ($result['code'] === 0) {
                    $service->update(['status' => 'active']);
                    event(new ServiceUnsuspend($service, isset($payload['mail'])));
                }
                break;
            case 'terminate':
                $result = $this->terminate();
                if ($result['code'] === 0) {
                    $service->update(['status' => 'terminated']);
                    event(new ServiceTerminate($service));
                }
                break;
            case 'change_password':
                if (is_null($payload)) $password = $this->service->password;
                else $password = $payload;
                $result = $this->changePassword($password);
                if ($result['code'] === 0) {
                    $this->service->password = $password;
                    $this->service->save();
                }
                break;
            default:
                if (!method_exists($this, $command))
                    return ['code' => -1, 'msg' => 'Method does not Exist'];
                if (!is_callable([$this, $command]))
                    return ['code' => -2, 'msg' => 'Method is not callable'];
                $result = $this->$command($payload);
        }
        return $result;
    }
}