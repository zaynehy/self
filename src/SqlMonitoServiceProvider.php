<?php
/**
 * Created by PhpStorm.
 * User: zhanghy<zhanghongyan@100tal.com>
 * Date: 2019-11-04
 * Time: 23:49
 */

namespace Zanehy\SqlMonitor;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class SqlMonitorServiceProvider extends ServiceProvider
{

    /**
     * The Laravel application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    public $app;


    /**
     * SqlMonitorServiceProvider constructor.
     * @param $app
     */
    public function __construct($app)
    {
        parent::__construct($app);
        $this->app = app();
    }


    /**
     * Register services.
     * @return void
     */
    public function register()
    {
        //获取配置文件路径并合并配置
        $configPath = __DIR__ . '/../config/monitor.php';
        $this->mergeConfigFrom($configPath, 'monitor');
    }


    /**
     * 合并配置文件
     * @auther zhanghy<zhanghongyan@100tal.com>
     * @Date 2019-11-10
     * @param string $path
     * @param string $key
     */
    protected function mergeConfigFrom($path, $key)
    {
        $config = $this->app['config']->get($key, []);
        $this->app['config']->set($key, array_merge(require $path, $config));
    }


    /**
     * 启动
     * @auther zhanghy<zhanghongyan@100tal.com>
     * @Date 2019-11-06
     */
    public function boot()
    {

        if (($this->app['config']->get('monitor.app') == "local") || $this->app['config']->get('monitor.sql-monitor')) {

            $db = $this->app['db'];
            $db->listen(
                function ($query) {
                    if ( $query instanceof \Illuminate\Database\Events\QueryExecuted ) {

                        //获取当前监听的文件和行数
                        $stacks = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);

                        //打印监听日志
                        $this->sqlMonitor($query, $stacks);
                    } else {
                        Log::error("sqlmonitorError: Not listening QueryExecuted");
                    }
                }
            );
        }
    }



    /**
     * 监听
     * @auther zhanghy<zhanghongyan@100tal.com>
     * @Date 2019-11-05
     * @param QueryExecuted $event
     */
    private function sqlMonitor(QueryExecuted $event, array $stacks)
    {
        $sql = str_replace("?", "'%s'", $event->sql);
        $sql = vsprintf($sql, $event->bindings);
        $urlPath = Request::capture()->path();

        //检索执行的文件和行数
        $res = [];
        foreach ($stacks as $stack) {
            if (isset($stack['class']) && isset($stack['file']) && !$this->fileIsInExcludedPath($stack['file'])) {
                $stack['file'] = $this->normalizeFilename($stack['file']);
                $res = $stack;
                break;
            }
        }

        //记录日志
        Log::info(json_encode([
            'action' => 'sqlmonitor:'. $urlPath,
            'path' => $urlPath,
            'sql' => $sql,
            'time' => $event->time . 'ms',
            'file' => ($res['file'] ?? ''),
            'line' => ($res['line'] ?? '')
        ]));
    }

    /**
     * 检查给定文件是否要从分析中排除
     * @auther zhanghy<zhanghongyan@100tal.com>
     * @Date 2019-11-05
     * @param $file
     * @return bool
     */
    private function fileIsInExcludedPath($file)
    {
        $excludedPaths = [
            '/vendor/laravel/framework/src/Illuminate/Database',
            '/vendor/laravel/framework/src/Illuminate/Events',
            '/vendor/barryvdh/laravel-debugbar',
        ];

        $normalizedPath = str_replace('\\', '/', $file);
        foreach ($excludedPaths as $excludedPath) {
            if (strpos($normalizedPath, $excludedPath) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 通过删除相对链接和基本目录来缩短路径
     * @auther zhanghy<zhanghongyan@100tal.com>
     * @Date 2019-11-05
     * @param $path
     * @return mixed
     */
    private function normalizeFilename($path)
    {
        if (file_exists($path)) {
            $path = realpath($path);
        }
        return str_replace(base_path(), '', $path);
    }



}