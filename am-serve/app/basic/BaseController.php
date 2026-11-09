<?php
// +----------------------------------------------------------------------
// | atrman企业快速开发框架
// +----------------------------------------------------------------------
// | Author: raingad <raingad@foxmail.com>
// +----------------------------------------------------------------------
namespace app\basic;

use app\exception\ApiException;
use app\service\DataScopeService;
use support\think\Db;
/**
 * 基类 控制器继承此类
 */
abstract class BaseController
{

    /**
     * 当前登陆管理员信息
     */
    protected $userInfo;

    /**
     * 验证器注入
     */
    protected $validate;

    /**
     * 模型注入
     */
    protected $model;

    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        $userInfo = getCurrentInfo();
        if($userInfo){
            $this->userInfo = $userInfo;
        }
        // 控制器初始化
        $this->initialize();
    }

    /**
     * 初始化
     */
    protected function initialize(): void
    {
       
    }
    
    /**
     * 验证器调用
     */
    protected function validate(string $scene, $data): bool
    {
        if ($this->validate) {
            if (!$this->validate->scene($scene)->check($data)) {
                throw new ApiException($this->validate->getError());
            }
        }
        return true;
    }

     /**
     * 搜索器搜索
     * @param array $searchWhere
     * @return mixed
     */
    public function search($model,array $searchWhere = []): mixed
    {
        $withSearch = array_keys($searchWhere);
        $data = [];
        foreach ($searchWhere as $key => $value) {
            if ($value !== '' && $value !== null && $value !== []) {
                $data[$key] = $value;
            }
        }
        $withSearch = array_keys($data);
        return $model->withSearch($withSearch, $data);
    }

    /**
     * 批量赋值防护：剔除只该由服务端决定的字段。
     *
     * 前端整表单（尤其"编辑时把详情对象原样回传"的表单）会带上主键、审计人、时间戳，
     * 甚至软删标记；这些值一律以服务端事实为准，否则调用方可自行决定行的归属与可见性。
     *
     * @param array $data  原始 post 数据
     * @param array $extra 当前表额外的服务端字段
     */
    protected function stripServerFields(array $data, array $extra = []): array
    {
        return array_diff_key(
            $data,
            array_flip(array_merge(['id', 'delete_time', 'create_time', 'update_time', 'created_by', 'updated_by'], $extra))
        );
    }

    /**
     * 自动获取前端传递的分页数量
     * @param \think\Model|\think\model\relation\HasMany $model
     * @return \think\Paginator
     */
    protected function paginate($model): mixed
    {
        $request = request();
        // 分页条数由前端传入，但上限必须握在服务端：
        // size=100000 会把整表拉进内存，500 与 getList() 里"小表全量查询"的口径一致
        $size = min(max((int) $request->input('size', 20), 1), 500);
        return $model->paginate([
            'list_rows'=> $size,
            'var_page' => 'current',
        ]);
    }

    /**
     * 分页查询数据
     * @param mixed $model
     * @return mixed
     */
    public function getList($model): mixed
    {
        $request = request();
        $listType = $request->input('list_type', 'list');
        $orderField = $request->input('orderField', '');
        $orderType = $request->input("orderType", "desc");
        // 数据权限：opt-in 模型自动施加行级过滤（getList 收到的是 search() 返回的 Query，经 getModel() 读配置）
        if (!isSuperAdmin()) {
            $underlying = method_exists($model, 'getModel') ? $model->getModel() : null;
            if ($underlying && method_exists($underlying, 'dataScopeConfig')) {
                DataScopeService::applyToQuery($model, currentUserId(), $underlying->dataScopeConfig());
            }
        }
        if (empty($orderField)) {
            $orderField =  $model->getPk();
        }
        $model->order($orderField, $orderType);
        if ($listType === 'all') {
            // 如果数据量小于500条，直接查询所有数据，否则分页查询
            if($model->count()<500){
                return $model->select()->toArray();
            }
        }
        return $this->paginate($model);
    }

    // 🙆统一返回列表数据
    public function returnListData(mixed $list, $msg = '') : mixed
    {
        $data = is_array($list) ? $list : $list->toArray()['data'];
        return success($msg, $data,is_array($list) ? count($list) : $list->total(), is_array($list) ? 1 : $list->currentPage());
    }
    
    /**
     * 行政级别：只能操作严格低于自己级别的目标；超管例外
     *
     * 额外硬约束：
     *   - 任何操作者（包括另一个超管）都不能对超管对象本身做 delete / disable / reassign 这类破坏性操作，
     *     靠 targetLevel 比较无法覆盖"超管互杀"，这里兜一次，具体操作点再配合 currentUserId === $id 双保险。
     */
    protected function assertCanManage(int $targetLevel, ?int $targetUserId = null): void
    {
        // 超管保护：即使当前操作者也是超管，也不能动另一个超管
        if ($targetUserId !== null && isSuperAdmin($targetUserId)) {
            throw new ApiException('超级管理员账号受系统保护，不可操作');
        }
        if (isSuperAdmin()) {
            return;
        }
        if ($targetLevel >= currentLevel()) {
            throw new ApiException('无权操作同级或更高级别的数据');
        }
    }

    /**
     * 数据权限：单条可见校验（仅对 opt-in 模型生效）
     */
    protected function assertRowVisible($model, array $row): void
    {
        if (isSuperAdmin()) {
            return;
        }
        if (method_exists($model, 'dataScopeConfig') && !DataScopeService::canSee(currentUserId(), $row, $model->dataScopeConfig())) {
            throw new ApiException('无权限访问该数据');
        }
    }

    /**
     * 数据库事务操作
     * @param callable $closure
     * @param bool $isTran
     * @return mixed
     */
    public function transaction(callable $closure, bool $isTran = true): mixed
    {
        return $isTran ? Db::transaction($closure) : $closure();
    }

}
