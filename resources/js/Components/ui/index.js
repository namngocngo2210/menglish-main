/**
 * Đăng ký toàn cục bộ component giao diện (Ui*) + hàm tiện ích dùng trong template:
 *   route('holidays.index'), routeIs('crm.*'), can('lead.create'), formatMoney(v), formatDate(v, 'd/m/Y'), formatNumber(v), shortCode(code)
 * → trang Vue không cần import từng component / hàm.
 */
import { route, routeIs } from '@/lib/route';
import { can, canAny } from '@/lib/can';
import { formatDate, formatMoney, formatNumber, shortCode } from '@/lib/format';

const components = import.meta.glob('./Ui*.vue', { eager: true });

export default {
    install(app) {
        for (const [path, module] of Object.entries(components)) {
            app.component(path.split('/').pop().replace('.vue', ''), module.default);
        }
        Object.assign(app.config.globalProperties, { route, routeIs, can, canAny, formatDate, formatMoney, formatNumber, shortCode });
    },
};
