const options = [
      { label: '选项1', value: 1, border:true },
      { label: '选项2', value: 2, border:true },
      { label: '选项3', value: 3, border:true }
    ]
const componentLibrary = [{
    title: "基础组件",
    operation: [{
            type: 'input',
            name: '单行文本',
            icon: 'ri:input-method-line',
            options: {
                placeholder: '请输入内容',
                showWordLimit: false,
                maxlength: 128,
                showConfig:['showWordLimit','maxlength','default']
            }
        },
        {
            type: 'textarea',
            name: '多行文本',
            icon: 'ri:file-text-line',
            options: {
                placeholder: '请输入内容',
                showWordLimit: false,
                maxlength: 1024,
                showConfig:['showWordLimit','maxlength','default']
            }
        },
        {
            type: 'radiogroup',
            name: '单选框',
            icon: 'ri:radio-button-line',
            options: {
                options,
            },
        },
        {
            type: 'checkboxgroup',
            name: '多选框',
            icon: 'ri:checkbox-multiple-line',
            options: {
                options,
                defaultValue: [],
            },
        },
        {
            type: 'switch',
            name: '开关',
            icon: 'ri:toggle-line',
        },
        {
            type: 'select',
            name: '下拉选择器',
            icon: 'ri:list-check-2',
            options: {
                options,
                placeholder: '请选择数据',
                showType: 1
            },
        },
        {
            type: 'inputtag',
            name: '标签选择器',
            icon: 'ri:price-tag-3-line',
            options:{
                defaultValue:[],
                placeholder: '请输入标签',
            }
        },
        {
            type: 'color',
            name: '颜色选择器',
            icon: 'ri:palette-line',
            options:{
                defaultValue: '',
                placeholder: '请选择颜色',
            }
        },
        {
            type: 'input',
            name: '手机号',
            icon: 'ri:smartphone-line',
            options: {
                rules: [
                    { type: 'pattern', pattern: /^1[3456789]\d{9}$/, message: '请输入正确的手机号格式', trigger: ['blur'] },
                ],
            }
        },
        {
            type: 'input',
            name: '邮箱',
            icon: 'ri:mail-line',
            options: {
                rules: [
                    { type: 'email', message: '请输入正确的邮箱格式', trigger: ['blur'] },
                    { required: true, message: '请输入邮箱', trigger: ['blur'] },
                ]
            }
        },
        {
            type: 'number',
            name: '数字',
            icon: 'ri:hashtag',
            options: {
                defaultValue: 0,
            }
        },
         {
            type: 'money',
            name: '金额',
            icon: 'ri:money-cny-circle-line',
            options: {
               defaultValue: 0,
            }
        },
        {
            type: 'date',
            name: '日期',
            icon: 'ri:calendar-line',
        },
        {
            type: 'datetime',
            name: '日期时间',
            icon: 'ri:calendar-event-line',
            options: {
                placeholder: '请选择日期时间',
            }
        },
        {
            type: 'daterange',
            name: '日期区间',
            icon: 'ri:calendar-schedule-line',
            options: {
                startPlaceholder: '请选择开始日期',
                endPlaceholder: '请选择结束日期',
            }
        },
        {
            type: 'remark',
            name: '说明',
            icon: 'ri:information-line',
            options: {
                defaultValue: '请输入说明内容',
            }
        }
    ]
}, {
    title: '高级组件',
    operation: [
        {
            type: 'image',
            name: '图片',
            icon: 'ri:image-line',
            options: {
                placeholder: '请选择图片',
                props: {
                    type: 1,
                }
            }
        },
        {
            type: 'file',
            name: '附件',
            icon: 'ri:attachment-2',
            options: {
                placeholder: '请选择附件',
                props: {
                    type: 2,
                }
            }
        },
        {
            type: 'user',
            name: '成员',
            icon: 'ri:user-3-line',
        },
        {
            type: 'dept',
            name: '部门',
            icon: 'ri:node-tree',
            options: {
                props: {
                    defaultExpandAll: true,
                    checkStrictly: true,
                    clearable: true,
                },
            }
        },
        {
            type: 'structure',
            name: '组织架构',
            icon: 'ri:organization-chart',
            options: {
                placeholder: '请选择组织架构对象',
                props: {
                    displayData: [],
                },
            }
        },{
            type: 'icon',
            name: '图标选择器',
            icon: 'ri:remixicon-line',
        },{
            type: 'editor',
            name: '富文本编辑器',
            icon: 'ri:edit-box-line',
            options: {
                placeholder: '请输入内容',
                defaultValue: '',
                showWordLimit: false,
                maxlength: 2048,
                showConfig:['showWordLimit','maxlength','default']
            }
        }]
}];

export default componentLibrary
