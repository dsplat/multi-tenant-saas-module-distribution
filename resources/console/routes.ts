import type { RouteRecordRaw } from 'vue-router'
import { view } from '@multi-tenant-saas/console/module-loader'

const routes: RouteRecordRaw[] = [
  // 分销管理
  { path: 'distribution', name: 'DistributionManagement', component: view('distribution', 'DistributionManagement'), meta: { title: '分销管理', permission: 'sales.view' } },
]

export default routes
