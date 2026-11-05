<template>
<ArtForm
    ref="formRef"
    v-model="form"
    :items="formItems"
    :rules="rules"
    :span="24"
    :gutter="20"
    label-width="100px"
    :show-reset="false"
    :show-submit="false"
    label-position="top"
  >
    <template #options>
      <amDict
        dict="options_type"
        mode="button"
        v-model="selectedItem.optionsType"
      />
        <VueDraggable
      v-model="(selectedItem.options as any[])"
      :animation="150"
      handle=".handle"
      ghost-class="ghost"
      class="options-config"
      v-if="selectedItem.optionsType === 1"
    >
          <div 
            v-for="(option, index) in (selectedItem.options || [])" 
            :key="index"
            class="option-item"
          >
            <artSvgIcon class="handle text-lg text-gray-500" icon="ri:draggable" />
            <ElInput 
              v-model="option.label" 
              placeholder="选项文本"
              class="option-label"
            />
            <ElInput 
              v-model="option.value" 
              placeholder="选项值"
              class="option-value"
            />
            <ElButton type="danger" :icon="Minus" circle @click="removeOption(selectedItem,index)" size="small" />
          </div>
          <ElButton 
            type="primary" 
            size="small"
            class="add-more"
            @click="addOption(selectedItem)"
          >
            添加选项
          </ElButton>
        </VueDraggable>
        <ElSelect
        v-model="selectedItem.dict"
        placeholder="请选择字典类型"
        class="dict-select"
        clearable
        v-if="selectedItem.optionsType==2"
        @change="setOptions"
      >
        <ElOption
          v-for="(item,index) in dictList"
          :key="index"
          :label="item.name+'-['+item.code+']'"
          :value="item.code"
        />
      </ElSelect>
      <div class="remote-body" v-if="selectedItem.optionsType==3">
        <template v-if="selectedItem.type==='select'">
        <div class="flex items-center gap-2">
          <span>显示方式：</span>
          <amDict
            dict="show_type"
            mode="border"
            v-model="selectedItem.showType"
            @change="setRemoteDataList"
          />
        </div>
        <div class="flex items-center gap-2 remote-input">
          <div class="flex-1">
            <ElSelect
              v-model="selectedItem.remote"
              placeholder="请选择远程数据列表"
              clearable
              @change="setRemoteDataOptions"
            >
              <ElOption
                v-for="(item,index) in remoteDataList"
                :key="index"
                :label="item.name+'-['+item.code+']'"
                :value="item.code"
              />
            </ElSelect>
          </div>
        </div>
        </template>
        <template v-else>
          <el-alert title="远程数据仅支持下拉选择器" type="info" :closable="false" show-icon/>
        </template>
      </div>
  </template>
</ArtForm>
</template>
<script setup lang="ts">
import { FormItem } from '../types'
import { Minus } from '@element-plus/icons-vue'
import { VueDraggable } from 'vue-draggable-plus'
import { createLabelTooltip } from '@/utils/custom'
import AmDict from '@/components/business/am-dict/form/index.vue'
import { useDictStore } from '@/store/modules/dict'
import { useRemoteDataStore } from '@/store/modules/remoteData'

const dictStore = useDictStore()
const remoteDataStore = useRemoteDataStore()
const dictList = ref<any[]>([])
const remoteDataList = ref<any[]>([])



// Props
const props = defineProps<{
  selectedItem: FormItem,
  fieldList?: any[]
}>()

onMounted(async () => {
  await dictStore.fetchDictData()
  dictList.value = dictStore.dictData || []
  setRemoteDataList();
})

// 需要验证key是否唯一
const validateKey = (rule: any, value: string, callback: any) => {
  if (props.fieldList?.some((item: any) => item=== value)) {
    callback(new Error('字段标识已存在'))
  } else {
    callback()
  }
}

const rules = ref({
  key: [
    { required: true, message: '请输入字段标识', trigger: 'blur' },
    { validator: validateKey, message: '字段标识已存在', trigger: 'blur' },
  ],
  name: [{ required: true, message: '请输入显示名称', trigger: 'blur' }],
})

const form = computed(() => props.selectedItem)

/**
 * 表单项配置
 */
const formItems = computed(() => {
  let multipleName="选择方式";
  let multipleType=[{ label: '单选', border: true, value: false }, { label: '多选', border: true, value: true }];
const structureTypeOptions=[{label:'部门',value:1,border:true},{label:'标签',value:2,border:true},{label:'成员',value:3,border:true}];
return [
    { label: '字段标识', key: 'key', type: 'input', props: { placeholder: '请输入字段标识' }},
    { label: '字段名称', key: 'name', type: 'input', props: { placeholder: '请输入显示名称' }},
    { label: '提示文字', key: 'placeholder', type: 'input', props: { placeholder: '请输入提示文字' }},
    { label: createLabelTooltip('帮助信息', '帮助信息提示，显示在字段名称右方'), key: 'tooltip', type: 'textarea', props: { placeholder: '帮助信息' }},
    { label: '默认值', key: 'defaultValue', type: 'input',hidden: !['input','textarea','color','remark','number','editor'].includes(props.selectedItem.type), props: { placeholder: '默认值' }},
    { label: '配置项', key: 'options', type: 'input', props: { placeholder: '' }  ,hidden:!['select','checkboxgroup','radiogroup'].includes(props.selectedItem.type)},
    { label: '是否必填', key: 'required', type: 'checkbox' ,span: 12 },
    { label: '是否显示字数限制', key: 'showWordLimit', type: 'checkbox',hidden:!props.selectedItem.showConfig.includes('showWordLimit') ,span: 12},
    { label: createLabelTooltip('最大字数', '最大输入字数'), key: 'maxlength', type: 'number' ,hidden:!props.selectedItem.showConfig.includes('maxlength')},
    { label:multipleName, key: 'multiple', type: 'radiogroup' , props: { options:  multipleType},hidden: !['image','file','user','structure','dept','select'].includes(props.selectedItem.type)},
    { label: '选择类型', key: 'structureType', type: 'checkboxgroup',hidden:!['structure'].includes(props.selectedItem.type),options:structureTypeOptions},
    { label:createLabelTooltip('标签最大值', '标签最大可以选择的个数'), key: 'max', type: 'number',hidden:!['inputtag'].includes(props.selectedItem.type)},
    ]
})

const setOptions=(val : string)=>{
  const list=dictStore.getByCode(val);
  props.selectedItem.options=list.map((item:any)=>({label:item.name,value:item.value}));
}

const setRemoteDataOptions=async (val : string)=>{
  const list=await remoteDataStore.fetchOptions(val,{});
  props.selectedItem.options=list;
}

const setRemoteDataList=async ()=>{
  const list=await remoteDataStore.remoteList;
  remoteDataList.value=list.filter((item:any)=>item.type==props.selectedItem.showType);
}

// 添加选项
const addOption = (item: FormItem) => {
  if (!item.options) {
    item.options = []
  }
  item.options.push({ label: `选项${item.options.length + 1}`, value: `${item.options.length + 1}` })
}

// 删除选项
const removeOption = (item: FormItem, index: number) => {
  if (item.options && item.options.length > 0) {
    item.options.splice(index, 1)
  }
}
</script>
<style scoped>
.options-config {
  margin-top: 10px;
    .option-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding:5px;
      .handle {
        cursor: move;
      }
      .option-label,
      .option-value {
        flex: 1;
      }
      
    }
  }
  .ghost {
    border-radius: 4px;
    padding:3px 5px;
    border: 1px dashed var(--art-gray-400);
  }
  .add-more{
    margin-top: 10px;
  }
  .dict-select{
    margin-top: 15px;
  }
  .remote-body{
    margin-top: 15px;
    width: 100%;
    .remote-input{
      margin-top: 15px;
      width: 100%;
    }
    .remote-add{
      width:30px;
      .handle{
        cursor: pointer;
        font-size: 28px;
      }
    }
  }
</style>