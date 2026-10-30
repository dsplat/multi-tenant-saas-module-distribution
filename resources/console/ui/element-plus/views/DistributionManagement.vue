<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>分销管理</span>
        </div>
      </template>

      <el-tabs v-model="activeTab">
        <el-tab-pane label="分销员列表" name="distributors">
          <ProTable
            ref="distributorTableRef"
            :columns="distributorColumns"
            :request="handleDistributorRequest"
            :search-config="distributorSearchConfig"
            :actions="distributorActions"
          />
        </el-tab-pane>

        <el-tab-pane label="佣金规则" name="commission">
          <div class="tab-header">
            <el-button type="primary" @click="handleCreateRule"> 新建规则 </el-button>
          </div>
          <ProTable
            ref="ruleTableRef"
            :columns="ruleColumns"
            :request="handleRuleRequest"
            :search-config="ruleSearchConfig"
            :actions="ruleActions"
          />
        </el-tab-pane>

        <el-tab-pane label="提现审核" name="withdrawal">
          <ProTable
            ref="withdrawalTableRef"
            :columns="withdrawalColumns"
            :request="handleWithdrawalRequest"
            :search-config="withdrawalSearchConfig"
            :actions="withdrawalActions"
          />
        </el-tab-pane>

        <el-tab-pane label="分销关系图" name="tree">
          <div class="tab-header">
            <el-button :loading="treeLoading" @click="refreshTreeData"> 刷新 </el-button>
          </div>
          <div class="tree-container">
            <div v-if="treeLoading" class="tree-loading">
              <el-icon class="is-loading" :size="24">
                <Loading />
              </el-icon>
              <span>加载中...</span>
            </div>
            <div v-else-if="treeData.length === 0" class="tree-empty">
              <el-empty description="暂无分销关系数据" />
            </div>
            <div v-else class="tree-list">
              <div v-for="node in treeData" :key="node.id" class="tree-node-root">
                <TreeNode :node="node" :level="0" />
              </div>
            </div>
          </div>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <el-dialog
      v-model="ruleDialogVisible"
      :title="ruleDialogTitle"
      width="600px"
      :close-on-click-modal="false"
      @close="handleRuleDialogClose"
    >
      <el-form ref="ruleFormRef" :model="ruleFormData" :rules="ruleFormRules" label-width="100px">
        <el-form-item label="规则名称" prop="name">
          <el-input
            v-model="ruleFormData.name"
            placeholder="请输入规则名称"
            maxlength="50"
            show-word-limit
          />
        </el-form-item>
        <el-form-item label="分销等级" prop="level">
          <el-input-number
            v-model="ruleFormData.level"
            :min="1"
            :max="10"
            :step="1"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="佣金比例" prop="rate">
          <el-input-number
            v-model="ruleFormData.rate"
            :min="0.01"
            :max="100"
            :precision="2"
            :step="1"
            style="width: 100%"
          />
          <span class="form-tip">%</span>
        </el-form-item>
        <el-form-item label="最低金额" prop="minAmount">
          <el-input-number
            v-model="ruleFormData.minAmount"
            :min="0"
            :max="999999"
            :precision="2"
            :step="1"
            style="width: 100%"
          />
          <span class="form-tip">元</span>
        </el-form-item>
        <el-form-item label="最高金额" prop="maxAmount">
          <el-input-number
            v-model="ruleFormData.maxAmount"
            :min="0"
            :max="999999"
            :precision="2"
            :step="1"
            style="width: 100%"
          />
          <span class="form-tip">元</span>
        </el-form-item>
        <el-form-item label="规则描述" prop="description">
          <el-input
            v-model="ruleFormData.description"
            type="textarea"
            placeholder="请输入规则描述"
            :rows="3"
            maxlength="200"
            show-word-limit
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="ruleDialogVisible = false"> 取消 </el-button>
        <el-button type="primary" :loading="ruleSubmitting" @click="handleRuleSubmit">
          确定
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="auditDialogVisible"
      title="审核提现"
      width="480px"
      :close-on-click-modal="false"
    >
      <el-form ref="auditFormRef" :model="auditFormData" :rules="auditFormRules" label-width="80px">
        <el-form-item label="审核结果" prop="action">
          <el-radio-group v-model="auditFormData.action">
            <el-radio value="approve"> 通过 </el-radio>
            <el-radio value="reject"> 拒绝 </el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="审核备注" prop="remark">
          <el-input
            v-model="auditFormData.remark"
            type="textarea"
            placeholder="请输入审核备注"
            :rows="3"
            maxlength="200"
            show-word-limit
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="auditDialogVisible = false"> 取消 </el-button>
        <el-button type="primary" :loading="auditSubmitting" @click="handleAuditSubmit">
          确定
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, h, watch, defineComponent, type PropType } from 'vue'
import {
  ElMessage,
  ElMessageBox,
  ElTag,
  ElSwitch,
  ElIcon,
  type FormInstance,
  type FormRules,
  type FormItemRule,
} from 'element-plus'
import { Loading, ArrowRight } from '@element-plus/icons-vue'
import ProTable from '@multi-tenant-saas/console/components/common/ProTable/ProTable.vue'
import type {
  ColumnConfig,
  SearchConfig,
  ActionConfig,
  RequestParams,
  RequestResult,
} from '@multi-tenant-saas/console/components/common/ProTable/ProTable.vue'
import {
  getDistributorList,
  updateDistributorStatus,
  getCommissionRuleList,
  createCommissionRule,
  updateCommissionRule,
  deleteCommissionRule,
  toggleCommissionRule,
  getWithdrawalList,
  approveWithdrawal,
  rejectWithdrawal,
  getDistributorTree,
  type Distributor,
  type CommissionRule,
  type WithdrawalRecord,
  type DistributorTreeNode,
  type DistributorListParams,
  type CommissionRuleListParams,
  type WithdrawalListParams,
  type CreateCommissionRuleData,
  type UpdateCommissionRuleData,
} from '@modules/Distribution/api/distribution'
import { formatFen, fenToYuanNumber, yuanToFen } from '@multi-tenant-saas/ui-core/money'

defineOptions({ name: 'DistributionManagement' })

function maskBankAccount(account?: string): string {
  if (!account) return '-'
  if (account.length < 4) return '****'
  return '**** **** **** ' + account.slice(-4)
}

const activeTab = ref('distributors')

const TreeNode: any = defineComponent({
  name: 'TreeNode',
  props: {
    node: {
      type: Object as PropType<DistributorTreeNode>,
      required: true,
    },
    level: {
      type: Number,
      default: 0,
    },
  },
  setup(props) {
    const expanded = ref(props.level < 2)
    const hasChildren = props.node.children && props.node.children.length > 0

    return () =>
      h('div', { class: 'tree-node', style: { paddingLeft: `${props.level * 24}px` } }, [
        h('div', { class: 'tree-node-content' }, [
          hasChildren
            ? h(
                'span',
                {
                  class: 'tree-node-arrow',
                  onClick: () => (expanded.value = !expanded.value),
                  style: { transform: expanded.value ? 'rotate(90deg)' : 'rotate(0deg)' },
                },
                [h(ArrowRight, { size: 14 })],
              )
            : h('span', { class: 'tree-node-arrow-placeholder' }),
          h('span', { class: 'tree-node-name' }, props.node.name),
          h(
            ElTag,
            { size: 'small', type: 'info', class: 'tree-node-tag' },
            () => `等级${props.node.level}`,
          ),
          h(
            'span',
            { class: 'tree-node-info' },
            `佣金: ${formatFen(props.node.totalCommission)}`,
          ),
          h('span', { class: 'tree-node-info' }, `下级: ${props.node.subordinateCount ?? 0}`),
        ]),
        hasChildren && expanded.value
          ? h(
              'div',
              { class: 'tree-node-children' },
              props.node.children!.map((child) =>
                h(TreeNode, { node: child, level: props.level + 1, key: child.id }),
              ),
            )
          : null,
      ])
  },
})

const distributorTableRef = ref<InstanceType<typeof ProTable>>()
const ruleTableRef = ref<InstanceType<typeof ProTable>>()
const withdrawalTableRef = ref<InstanceType<typeof ProTable>>()

const treeData = ref<DistributorTreeNode[]>([])
const treeLoading = ref(false)
const treeLoaded = ref(false)

const levelOptions = [
  { label: '等级1', value: 1 },
  { label: '等级2', value: 2 },
  { label: '等级3', value: 3 },
  { label: '等级4', value: 4 },
  { label: '等级5', value: 5 },
]

const statusOptions = [
  { label: '正常', value: 'active' },
  { label: '冻结', value: 'frozen' },
]

const ruleStatusOptions = [
  { label: '启用', value: 'enabled' },
  { label: '禁用', value: 'disabled' },
]

const withdrawalStatusOptions = [
  { label: '待审核', value: 'pending' },
  { label: '已通过', value: 'approved' },
  { label: '已拒绝', value: 'rejected' },
]

function getStatusLabel(status?: string) {
  return statusOptions.find((o) => o.value === status)?.label ?? status ?? '-'
}

function getStatusTagType(status?: string) {
  const map: Record<string, 'success' | 'danger' | 'info'> = { active: 'success', frozen: 'danger' }
  return map[status ?? ''] ?? 'info'
}

function getWithdrawalStatusLabel(status?: string) {
  return withdrawalStatusOptions.find((o) => o.value === status)?.label ?? status ?? '-'
}

function getWithdrawalTagType(status?: string) {
  const map: Record<string, 'warning' | 'success' | 'danger' | 'info'> = {
    pending: 'warning',
    approved: 'success',
    rejected: 'danger',
  }
  return map[status ?? ''] ?? 'info'
}

const distributorSearchConfig: SearchConfig[] = [
  { prop: 'name', label: '分销员姓名', type: 'input', placeholder: '请输入分销员姓名' },
  {
    prop: 'level',
    label: '等级',
    type: 'select',
    placeholder: '请选择等级',
    options: levelOptions,
  },
  {
    prop: 'status',
    label: '状态',
    type: 'select',
    placeholder: '请选择状态',
    options: statusOptions,
  },
]

const distributorColumns: ColumnConfig[] = [
  { prop: 'id', label: 'ID', width: 80 },
  { prop: 'name', label: '姓名', minWidth: 120 },
  {
    prop: 'level',
    label: '等级',
    width: 100,
    render: (row: Distributor) => h(ElTag, { type: 'info' }, () => `等级${row.level}`),
  },
  {
    prop: 'totalCommission',
    label: '累计佣金',
    width: 120,
    render: (row: Distributor) => h('span', null, formatFen(row.totalCommission)),
  },
  {
    prop: 'availableCommission',
    label: '可提现佣金',
    width: 120,
    render: (row: Distributor) =>
      h('span', null, formatFen(row.availableCommission)),
  },
  { prop: 'subordinateCount', label: '下级数量', width: 100 },
  { prop: 'parentName', label: '上级分销员', width: 120 },
  {
    prop: 'status',
    label: '状态',
    width: 100,
    render: (row: Distributor) =>
      h(ElTag, { type: getStatusTagType(row.status) }, () => getStatusLabel(row.status)),
  },
  { prop: 'createdAt', label: '加入时间', width: 170, sortable: true },
]

const distributorActions: ActionConfig[] = [
  {
    label: '冻结',
    type: 'warning',
    onClick: (row) => handleToggleDistributorStatus(row as Distributor, 'frozen'),
    visible: (row) => (row as Distributor).status === 'active',
  },
  {
    label: '解冻',
    type: 'success',
    onClick: (row) => handleToggleDistributorStatus(row as Distributor, 'active'),
    visible: (row) => (row as Distributor).status === 'frozen',
  },
]

const ruleSearchConfig: SearchConfig[] = [
  { prop: 'name', label: '规则名称', type: 'input', placeholder: '请输入规则名称' },
  {
    prop: 'status',
    label: '状态',
    type: 'select',
    placeholder: '请选择状态',
    options: ruleStatusOptions,
  },
]

const ruleColumns: ColumnConfig[] = [
  { prop: 'id', label: 'ID', width: 80 },
  { prop: 'name', label: '规则名称', minWidth: 150 },
  { prop: 'level', label: '适用等级', width: 100 },
  {
    prop: 'rate',
    label: '佣金比例',
    width: 120,
    render: (row: CommissionRule) => h('span', null, `${row.rate}%`),
  },
  {
    prop: 'minAmount',
    label: '最低金额',
    width: 120,
    render: (row: CommissionRule) => h('span', null, formatFen(row.minAmount)),
  },
  {
    prop: 'maxAmount',
    label: '最高金额',
    width: 120,
    render: (row: CommissionRule) => h('span', null, formatFen(row.maxAmount)),
  },
  {
    prop: 'status',
    label: '状态',
    width: 100,
    render: (row: CommissionRule) =>
      h(ElSwitch, {
        modelValue: row.status === 'enabled',
        'onUpdate:modelValue': (val: string | number | boolean) =>
          handleToggleRuleStatus(row, !!val),
        inlinePrompt: true,
      }),
  },
  { prop: 'createdAt', label: '创建时间', width: 170, sortable: true },
]

const ruleActions: ActionConfig[] = [
  { label: '编辑', type: 'primary', onClick: (row) => handleEditRule(row as CommissionRule) },
  { label: '删除', type: 'danger', onClick: (row) => handleDeleteRule(row as CommissionRule) },
]

const withdrawalSearchConfig: SearchConfig[] = [
  { prop: 'distributorName', label: '分销员姓名', type: 'input', placeholder: '请输入分销员姓名' },
  {
    prop: 'status',
    label: '审核状态',
    type: 'select',
    placeholder: '请选择状态',
    options: withdrawalStatusOptions,
  },
]

const withdrawalColumns: ColumnConfig[] = [
  { prop: 'id', label: 'ID', width: 80 },
  { prop: 'distributorName', label: '分销员', minWidth: 120 },
  {
    prop: 'amount',
    label: '提现金额',
    width: 120,
    render: (row: WithdrawalRecord) => h('span', null, formatFen(row.amount)),
  },
  { prop: 'bankName', label: '银行', width: 120 },
  {
    prop: 'bankAccount',
    label: '银行卡号',
    width: 180,
    render: (row: WithdrawalRecord) => h('span', null, maskBankAccount(row.bankAccount)),
  },
  {
    prop: 'status',
    label: '状态',
    width: 100,
    render: (row: WithdrawalRecord) =>
      h(ElTag, { type: getWithdrawalTagType(row.status) }, () =>
        getWithdrawalStatusLabel(row.status),
      ),
  },
  { prop: 'remark', label: '申请备注', minWidth: 150, showOverflowTooltip: true },
  { prop: 'auditRemark', label: '审核备注', minWidth: 150, showOverflowTooltip: true },
  { prop: 'createdAt', label: '申请时间', width: 170, sortable: true },
]

const withdrawalActions: ActionConfig[] = [
  {
    label: '审核',
    type: 'primary',
    onClick: (row) => handleAudit(row as WithdrawalRecord),
    visible: (row) => (row as WithdrawalRecord).status === 'pending',
  },
]

async function handleDistributorRequest(params: RequestParams): Promise<RequestResult> {
  try {
    const query: DistributorListParams = {
      page: params.page,
      pageSize: params.pageSize,
    }
    if (params.name) query.name = params.name
    if (params.level !== undefined && params.level !== '') query.level = Number(params.level)
    if (params.status) query.status = params.status
    const res = await getDistributorList(query)
    return { data: res.data ?? [], total: res.total ?? 0 }
  } catch (e: any) {
    ElMessage.error(e.message || '获取分销员列表失败')
    return { data: [], total: 0 }
  }
}

async function handleToggleDistributorStatus(row: Distributor, status: 'active' | 'frozen') {
  const label = status === 'frozen' ? '冻结' : '解冻'
  try {
    await ElMessageBox.confirm(`确定${label}该分销员吗？`, '提示', { type: 'warning' })
    await updateDistributorStatus(row.id, status)
    ElMessage.success(`分销员已${label}`)
    distributorTableRef.value?.refresh()
  } catch (e: any) {
    if (e !== 'cancel') {
      ElMessage.error(e.message || `${label}失败`)
    }
  }
}

const ruleDialogVisible = ref(false)
const ruleDialogTitle = ref('新建规则')
const ruleSubmitting = ref(false)
const ruleFormRef = ref<FormInstance>()
const editingRuleId = ref<number | null>(null)

const defaultRuleFormData = {
  name: '',
  level: 1,
  rate: 10,
  minAmount: 0,
  maxAmount: 0,
  description: '',
}

const ruleFormData = reactive({ ...defaultRuleFormData })

const ruleFormRules: FormRules = {
  name: [{ required: true, message: '请输入规则名称', trigger: 'blur' }],
  level: [{ required: true, message: '请选择分销等级', trigger: 'change' }],
  rate: [
    { required: true, message: '请输入佣金比例', trigger: 'blur' },
    {
      validator: (_rule: FormItemRule, val: number, callback: (error?: Error) => void) => {
        if (val === undefined || val === null || val <= 0 || val > 100) {
          callback(new Error('佣金比例必须大于0且不超过100'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
  minAmount: [{ required: true, message: '请输入最低金额', trigger: 'blur' }],
  maxAmount: [
    { required: true, message: '请输入最高金额', trigger: 'blur' },
    {
      validator: (_rule: FormItemRule, val: number, callback: (error?: Error) => void) => {
        if (val < ruleFormData.minAmount) {
          callback(new Error('最高金额不能小于最低金额'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
}

async function handleRuleRequest(params: RequestParams): Promise<RequestResult> {
  try {
    const query: CommissionRuleListParams = {
      page: params.page,
      pageSize: params.pageSize,
    }
    if (params.name) query.name = params.name
    if (params.status) query.status = params.status
    const res = await getCommissionRuleList(query)
    return { data: res.data ?? [], total: res.total ?? 0 }
  } catch (e: any) {
    ElMessage.error(e.message || '获取佣金规则列表失败')
    return { data: [], total: 0 }
  }
}

function resetRuleForm() {
  Object.assign(ruleFormData, { ...defaultRuleFormData })
}

function handleCreateRule() {
  ruleDialogTitle.value = '新建规则'
  editingRuleId.value = null
  resetRuleForm()
  ruleDialogVisible.value = true
}

function handleEditRule(row: CommissionRule) {
  ruleDialogTitle.value = '编辑规则'
  editingRuleId.value = row.id
  ruleFormData.name = row.name
  ruleFormData.level = row.level
  ruleFormData.rate = row.rate
  ruleFormData.minAmount = fenToYuanNumber(row.minAmount)
  ruleFormData.maxAmount = fenToYuanNumber(row.maxAmount)
  ruleFormData.description = row.description || ''
  ruleDialogVisible.value = true
}

async function handleRuleSubmit() {
  if (!ruleFormRef.value) return
  try {
    await ruleFormRef.value.validate()
  } catch {
    return
  }

  const payload: CreateCommissionRuleData = {
    name: ruleFormData.name,
    level: ruleFormData.level,
    rate: ruleFormData.rate,
    minAmount: yuanToFen(ruleFormData.minAmount),
    maxAmount: yuanToFen(ruleFormData.maxAmount),
    description: ruleFormData.description || undefined,
  }

  ruleSubmitting.value = true
  try {
    if (editingRuleId.value !== null) {
      await updateCommissionRule(editingRuleId.value, payload as UpdateCommissionRuleData)
    } else {
      await createCommissionRule(payload)
    }
    ElMessage.success('操作成功')
    ruleDialogVisible.value = false
    ruleTableRef.value?.refresh()
  } catch (e: any) {
    ElMessage.error(e.message || '操作失败')
  } finally {
    ruleSubmitting.value = false
  }
}

async function handleDeleteRule(row: CommissionRule) {
  try {
    await ElMessageBox.confirm('确定删除该佣金规则吗？', '提示', { type: 'warning' })
    await deleteCommissionRule(row.id)
    ElMessage.success('删除成功')
    ruleTableRef.value?.refresh()
  } catch (e: any) {
    if (e !== 'cancel') {
      ElMessage.error(e.message || '删除失败')
    }
  }
}

async function handleToggleRuleStatus(row: CommissionRule, enabled: boolean) {
  const newStatus = enabled ? 'enabled' : 'disabled'
  const prevStatus = row.status
  row.status = newStatus as 'enabled' | 'disabled'
  try {
    await toggleCommissionRule(row.id, newStatus as 'enabled' | 'disabled')
    ElMessage.success(enabled ? '已启用' : '已禁用')
  } catch (e: any) {
    row.status = prevStatus
    ElMessage.error(e.message || '操作失败')
  }
}

function handleRuleDialogClose() {
  ruleFormRef.value?.resetFields()
}

const auditDialogVisible = ref(false)
const auditSubmitting = ref(false)
const auditFormRef = ref<FormInstance>()
const currentWithdrawalId = ref<number | null>(null)

const auditFormData = reactive({
  action: 'approve' as 'approve' | 'reject',
  remark: '',
})

const auditFormRules: FormRules = {
  action: [{ required: true, message: '请选择审核结果', trigger: 'change' }],
  remark: [
    {
      validator: (_rule: FormItemRule, val: string, callback: (error?: Error) => void) => {
        if (auditFormData.action === 'reject' && !val?.trim()) {
          callback(new Error('拒绝时必须填写审核备注'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
}

async function handleWithdrawalRequest(params: RequestParams): Promise<RequestResult> {
  try {
    const query: WithdrawalListParams = {
      page: params.page,
      pageSize: params.pageSize,
    }
    if (params.distributorName) query.distributorName = params.distributorName
    if (params.status) query.status = params.status
    const res = await getWithdrawalList(query)
    return { data: res.data ?? [], total: res.total ?? 0 }
  } catch (e: any) {
    ElMessage.error(e.message || '获取提现列表失败')
    return { data: [], total: 0 }
  }
}

function handleAudit(row: WithdrawalRecord) {
  currentWithdrawalId.value = row.id
  auditFormData.action = 'approve'
  auditFormData.remark = ''
  auditDialogVisible.value = true
}

async function handleAuditSubmit() {
  if (!auditFormRef.value) return
  try {
    await auditFormRef.value.validate()
  } catch {
    return
  }

  if (currentWithdrawalId.value === null) return

  auditSubmitting.value = true
  try {
    if (auditFormData.action === 'approve') {
      await approveWithdrawal(currentWithdrawalId.value, auditFormData.remark || undefined)
      ElMessage.success('已通过')
    } else {
      await rejectWithdrawal(currentWithdrawalId.value, auditFormData.remark)
      ElMessage.success('已拒绝')
    }
    auditDialogVisible.value = false
    withdrawalTableRef.value?.refresh()
  } catch (e: any) {
    ElMessage.error(e.message || '审核失败')
  } finally {
    auditSubmitting.value = false
  }
}

async function loadTreeData() {
  treeLoading.value = true
  try {
    treeData.value = await getDistributorTree()
    treeLoaded.value = true
  } catch (e: any) {
    ElMessage.error(e.message || '获取分销关系图失败')
  } finally {
    treeLoading.value = false
  }
}

function refreshTreeData() {
  treeLoaded.value = false
  loadTreeData()
}

watch(activeTab, (val) => {
  if (val === 'tree' && !treeLoaded.value) {
    loadTreeData()
  }
})

watch(
  () => ruleFormData.minAmount,
  () => {
    if (ruleFormRef.value && ruleFormData.maxAmount !== undefined) {
      ruleFormRef.value.validateField('maxAmount')
    }
  },
)
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.tab-header {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 16px;
}

.form-tip {
  margin-left: 8px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  white-space: nowrap;
}

.tree-container {
  min-height: 400px;
}

.tree-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 300px;
  gap: 12px;
  color: var(--el-text-color-secondary);
}

.tree-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 300px;
}

.tree-node-root {
  margin-bottom: 8px;
}

.tree-node {
  padding: 8px 0;
}

.tree-node-content {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  border-radius: 6px;
  transition: background-color 0.2s;
}

.tree-node-content:hover {
  background-color: var(--el-fill-color-light);
}

.tree-node-arrow {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  cursor: pointer;
  transition: transform 0.2s;
  color: var(--el-text-color-secondary);
}

.tree-node-arrow:hover {
  color: var(--el-color-primary);
}

.tree-node-arrow-placeholder {
  width: 20px;
  height: 20px;
}

.tree-node-name {
  font-weight: 500;
  min-width: 100px;
}

.tree-node-tag {
  margin-left: 4px;
}

.tree-node-info {
  color: var(--el-text-color-secondary);
  font-size: 13px;
}

.tree-node-children {
  border-left: 1px dashed var(--el-border-color);
  margin-left: 10px;
}
</style>
