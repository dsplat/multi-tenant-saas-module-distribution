import { http, extractListResult, moduleApiPrefix } from '@multi-tenant-saas/console/shared/http'

export interface Distributor {
  id: number
  name: string
  avatar: string
  phone: string
  level: number
  totalCommission: number
  availableCommission: number
  subordinateCount: number
  parentId: number | null
  parentName: string
  status: 'active' | 'frozen'
  createdAt: string
  updatedAt: string
}

export interface CommissionRule {
  id: number
  name: string
  level: number
  rate: number
  minAmount: number
  maxAmount: number
  status: 'enabled' | 'disabled'
  description: string
  createdAt: string
  updatedAt: string
}

export interface WithdrawalRecord {
  id: number
  distributorId: number
  distributorName: string
  amount: number
  status: 'pending' | 'approved' | 'rejected'
  bankName: string
  bankAccount: string
  remark: string
  auditRemark: string
  createdAt: string
  updatedAt: string
}

export interface DistributorTreeNode {
  id: number
  name: string
  avatar: string
  level: number
  totalCommission: number
  subordinateCount: number
  children: DistributorTreeNode[]
}

export interface DistributorListParams {
  page: number
  pageSize: number
  name?: string
  level?: number
  status?: string
}

export interface DistributorListResult {
  data: Distributor[]
  total: number
}

export interface CommissionRuleListParams {
  page: number
  pageSize: number
  name?: string
  status?: string
}

export interface CommissionRuleListResult {
  data: CommissionRule[]
  total: number
}

export interface WithdrawalListParams {
  page: number
  pageSize: number
  status?: string
  distributorName?: string
}

export interface WithdrawalListResult {
  data: WithdrawalRecord[]
  total: number
}

export interface CreateCommissionRuleData {
  name: string
  level: number
  rate: number
  minAmount: number
  maxAmount: number
  description?: string
}

export interface UpdateCommissionRuleData {
  name?: string
  level?: number
  rate?: number
  minAmount?: number
  maxAmount?: number
  description?: string
}


export async function getDistributorList(
  params: DistributorListParams,
): Promise<DistributorListResult> {
  const res = await http.get<Distributor[]>(`${moduleApiPrefix('distribution')}/distributors`, { params })
  return extractListResult(res)
}

export async function getDistributorDetail(id: number): Promise<Distributor> {
  const res = await http.get<Distributor>(`${moduleApiPrefix('distribution')}/distributors/${id}`)
  return res.data
}

export async function updateDistributorStatus(
  id: number,
  status: 'active' | 'frozen',
): Promise<void> {
  await http.put(`${moduleApiPrefix('distribution')}/distributors/${id}/status`, { status })
}

export async function getCommissionRuleList(
  params: CommissionRuleListParams,
): Promise<CommissionRuleListResult> {
  const res = await http.get<CommissionRule[]>(`${moduleApiPrefix('distribution')}/commission-rules`, { params })
  return extractListResult(res)
}

export async function createCommissionRule(
  data: CreateCommissionRuleData,
): Promise<CommissionRule> {
  const res = await http.post<CommissionRule>(`${moduleApiPrefix('distribution')}/commission-rules`, data)
  return res.data
}

export async function updateCommissionRule(
  id: number,
  data: UpdateCommissionRuleData,
): Promise<CommissionRule> {
  const res = await http.put<CommissionRule>(`${moduleApiPrefix('distribution')}/commission-rules/${id}`, data)
  return res.data
}

export async function deleteCommissionRule(id: number): Promise<void> {
  await http.delete(`${moduleApiPrefix('distribution')}/commission-rules/${id}`)
}

export async function toggleCommissionRule(
  id: number,
  status: 'enabled' | 'disabled',
): Promise<void> {
  await http.put(`${moduleApiPrefix('distribution')}/commission-rules/${id}/status`, { status })
}

export async function getWithdrawalList(
  params: WithdrawalListParams,
): Promise<WithdrawalListResult> {
  const res = await http.get<WithdrawalRecord[]>(`${moduleApiPrefix('distribution')}/withdrawals`, { params })
  return extractListResult(res)
}

export async function approveWithdrawal(id: number, remark?: string): Promise<void> {
  await http.put(`${moduleApiPrefix('distribution')}/withdrawals/${id}/approve`, { remark })
}

export async function rejectWithdrawal(id: number, remark: string): Promise<void> {
  await http.put(`${moduleApiPrefix('distribution')}/withdrawals/${id}/reject`, { remark })
}

export async function getDistributorTree(id?: number): Promise<DistributorTreeNode[]> {
  const res = await http.get<DistributorTreeNode[]>(`${moduleApiPrefix('distribution')}/distributors/tree`, {
    params: { rootId: id },
  })
  return res.data ?? []
}
