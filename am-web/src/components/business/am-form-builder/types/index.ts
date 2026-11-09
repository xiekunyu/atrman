// 类型定义
export interface FormOption {
  label: string
  value: string | number | any
  [key: string]: any
}

export interface FormItem {
  id?: string | number
  /** 是否内置配置（1 为内置，不可编辑/删除） */
  is_sys?: number
  key: string
  name: string
  label: string
  type: string
  model: string
  rules?: any[]
  showConfig?: any
  defaultValue: any
  displayData?: any[]
  width: string
  placeholder: string
  tooltip?: string
  disabled: boolean
  maxlength?: number
  showWordLimit?: boolean
  controlsPosition?: string
  inline?: boolean
  showLabel?: boolean
  options?: FormOption[]
  isRange?: boolean
  startPlaceholder?: string
  endPlaceholder?: string
  readonly?: boolean
  editable?: boolean
  clearable?: boolean
  arrowControl?: boolean
  max?: number
  allowHalf?: boolean
  showAlpha?: boolean
  multiple?: boolean
  min?: number
  props?: Record<string, any>
  step?: number
  showInput?: boolean
  range?: boolean
  size?: {
    width: number
    height: number
  }
  optionsType?: number
  dict?: string
  structureType?: any
  showType?: number
  remoteOptions?: any[]
  remote?: string
  required?: boolean
  data?: any[]
}
