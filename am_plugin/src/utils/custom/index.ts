import { h } from 'vue'
import { ElTooltip, ElIcon } from 'element-plus'
import ArtSvgIcon from '@/components/core/base/art-svg-icon/index.vue'

  /**
   * 创建带 tooltip 的表单标签
   * @param label 标签文本
   * @param tooltip 提示文本
   * @returns 渲染函数
   */
  export const createLabelTooltip = (label: string, tooltip: string) => {
    return () =>
      h('span', { class: 'flex items-center' }, [
        h('span', label),
        h(
          ElTooltip,
          {
            content: tooltip,
            placement: 'top'
          },
          () => h(ArtSvgIcon, { class: 'ml-0.5 cursor-help text-lg', icon: 'ri:question-line' }, () => {})
        )
      ])
  }

// 获取颜色
export const getColor = (color: string, type: 'bg' | 'border' | 'text') => {
    // 如果没有指定颜色，使用默认主色调
    if (!color) {
      const colors = {
        bg: 'var(--el-color-primary-light-9)',
        border: 'var(--el-color-primary-light-8)',
        text: 'var(--el-color-primary)'
      }
      return colors[type]
    }

    // 根据类型返回不同的颜色变体
    switch (type) {
      case 'bg':
        // 背景色 - 更浅的版本
        return `${color}33`
      case 'border':
        // 边框色 - 中等亮度
        return `${color}66`
      case 'text':
        // 文字色 - 原始颜色
        return color
      default:
        return color
    }
  }

  // 获取金额的中文描述
  export const priceChange = (n: number) => {
      var fraction = ['角', '分'];
      var digit = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
      var unitOne = ['元', '万', '亿'];
      var unitTwo = ['', '拾', '佰', '仟'];

      var head = n < 0 ? '负' : '';
      n = Math.abs(n); // 绝对值

      var s = '';
      for (var i = 0; i < fraction.length; i++) {
        var cf = Math.pow(10, i + 1); // 10的i+1次方
        s += (digit[Math.floor(cf * n) % 10] + fraction[i]).replace(/零./, '');
      }

      n = Math.floor(n); // 向下取整
      for (var i = 0; i < unitOne.length && n > 0; i++) {
        var p = '';
        for (var j = 0; j < unitTwo.length && n > 0; j++) {
          p = digit[n % 10] + unitTwo[j] + p;
          n = Math.floor(n / 10);
        }
        s = p.replace(/(零.)*零$/, '').replace(/^$/, '零') + unitOne[i] + s;
      }

      if (s == '') {
        return '零元';
      }
      return head + s.replace(/(零.)*零元/, '元').replace(/(零.)+/g, '零');
    }

// 获取组织架构选择方式
export const  getStructureType=(arr: number[]) => {
  // 去重并只保留 1、2、3
  const nums = [...new Set(arr.filter(n => [1,2,3].includes(n)))];
  
  // 排序方便判断
  nums.sort();
  
  // 严格按你的规则匹配
  if (nums.length === 1) {
    if (nums[0] === 1) return 1;
    if (nums[0] === 2) return 2;
    if (nums[0] === 3) return 3;
  }
  
  if (nums.length === 2) {
    if (nums[0] === 1 && nums[1] === 2) return 5;
    if (nums[0] === 1 && nums[1] === 3) return 4;
    if (nums[0] === 2 && nums[1] === 3) return 6;
  }
  
  if (nums.length === 3) return 7;
  
  return 0;
}

export interface TreeNode {
  [key: string]: any;
}

interface FindTreeOptions {
  valueKey?: string;
  childrenKey?: string;
}

/**
 * 从树形结构中查找所有匹配的节点（找到多少返回多少）
 * @param tree 树数据
 * @param targetValue 目标值：数字 / 字符串 / 数组
 * @param options 配置项
 * @returns 所有匹配到的节点数组
 */
export const findTreeItems = (
  tree: TreeNode[],
  targetValue: number | string | (number | string)[],
  options: FindTreeOptions = {}
): TreeNode[] => {
  const { valueKey = 'value', childrenKey = 'children' } = options;
  const result: TreeNode[] = [];
  const targets = Array.isArray(targetValue) ? targetValue : [targetValue];

  // 递归遍历函数（定义在内部，合并成一个方法）
  function traverse(nodes: TreeNode[]) {
    for (const item of nodes) {
      // 匹配任意一个目标值就加入结果
      if (targets.includes(item[valueKey])) {
        result.push(item);
      }
      // 递归子节点
      if (Array.isArray(item[childrenKey])) {
        traverse(item[childrenKey]);
      }
    }
  }

  // 开始遍历
  traverse(tree);

  return result;
}

interface ListItem {
  [key: string]: any;
}

/**
 * 从普通一维列表中查找匹配项
 * @param list 普通数组列表
 * @param target 要查找的值：string | number | Array
 * @param options 配置项
 * @returns 所有匹配到的项，没匹配到返回 []
 */
export const findListItems = (
  list: ListItem[],
  target: number | string | (number | string)[],
  field: string
): ListItem[] => {
  const targets = new Set(Array.isArray(target) ? target : [target]);
  return list.filter(item => targets.has(item[field]));
}

// 将formConfig转换为JSON字符串，可以直接在am-form中使用
export const buildFormConfig = (formItems:any[]) => {
  const items :any=[];
  const rules :any=[];
  formItems.forEach((item:any) =>{
    let formItem={
      key:item.key,
      name:item.name,
      label:item.tooltip ? createLabelTooltip(item.name, item.tooltip) : item.name,
      type:item.type,
      placeholder:item?.placeholder || '',
      options:item.options,
      required:item?.required || false,
      props:{
          ...item.props,
          maxlength:item?.maxlength,
          // value: item?.defaultValue,
          showWordLimit:item?.showWordLimit || false,
          multiple:item?.multiple || false,
          optionsType:item?.optionsType || 1,
          dict:item?.dict || '',
          remote:item?.remote || ''
        }
      }
    if(item?.displayData){
      formItem.props.displayData=item.displayData;
    }
    if (item.type === 'structure') {
      const structureType=getStructureType(item?.structureType || [])
      formItem.props.type=structureType || 7
    }
    if (item.type === 'textarea') {
      formItem.type='input';
      formItem.props.type='textarea';
    }
    if (['radiogroup','checkboxgroup','select','treeselect'].includes(item.type)){
      if(item?.optionsType==1){
        formItem.props.options=item.options;
      }else if(item?.optionsType==2){
        // 字典数据type类型dict，需要type参数给组件确定表单展示方式
        formItem.props.type=item.type;
        formItem.type='dict';
        formItem.props.mode='border';
      }else{
        // 远程数据type类型为remote，需要type参数给组件确定表单展示方式
        formItem.props.type=item?.showType==1 ? 'select' : 'tree';
        formItem.type='remote';
      }
    }
    items.push(formItem)
    // 清理源数据中的重复规则（历史累积的脏数据），然后合并 required 规则
    if (Array.isArray(item.rules)) {
      // 按 JSON 去重，移除完全相同的重复规则
      const seen = new Set<string>()
      item.rules = item.rules.filter((r: any) => {
        const key = JSON.stringify(r)
        if (seen.has(key)) return false
        seen.add(key)
        return true
      })
    }
    // 先收集非 required 的其他规则
    let mergedRules: any[] = []
    if (Array.isArray(item.rules) && item.rules.length > 0) {
      mergedRules = item.rules.filter((r: any) => !r.required)
    }
    // 如果标记为必填，追加 required 规则
    if (item.required) {
      const requiredRule = { required: true, message: item.placeholder || '请输入', trigger: 'blur' }
      mergedRules.push(requiredRule)
    }
    // 有规则才写入
    if (mergedRules.length > 0) {
      rules[item.key] = mergedRules
    }
  })
  return {
    items:items,
    rules:rules
  }
}