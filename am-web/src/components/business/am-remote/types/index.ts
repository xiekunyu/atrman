// 数据源配置类型（前后端统一约定）
export interface RemoteConfig {
  id: string; // ID
  name: string; // 显示名称
  code: string; // 唯一标识
  api_path: string; // 接口地址
  method: number; // 1: get, 2: post
  type: number; // 1: 列表, 2: 树形
  label_field: string; // 展示字段
  value_field: string; // 值字段
  children_field?: string; // 树形子节点字段
  fixed_params?: Record<string, any>; // 固定参数
  [key: string]: any;
}


// 标准选项结构
export interface BaseOption {
  label: string;
  value: string | number;
  children?: BaseOption[];
  [key: string]: any;
}