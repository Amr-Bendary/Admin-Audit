import Component from 'flarum/common/Component';
import app from 'flarum/admin/app';

export default class AuditFilters extends Component {
  view() {
    const filters = this.attrs.filters;

    return (
      <div className="AdminAudit-filters">
        <div className="AdminAudit-filterItem AdminAudit-search">
          <i className="fas fa-search"></i>
          <input 
            className="FormControl" 
            type="text" 
            placeholder={app.translator.trans('bendary-admin-audit.admin.filters.search')}
            value={filters.q}
            onchange={(e) => this.attrs.onFilterChange({ q: e.target.value })}
          />
        </div>

        <div className="AdminAudit-filterItem">
          <select 
            className="FormControl"
            value={filters.category}
            onchange={(e) => this.attrs.onFilterChange({ category: e.target.value })}
          >
            <option value="">{app.translator.trans('bendary-admin-audit.admin.filters.all_categories')}</option>
            <option value="settings">{app.translator.trans('bendary-admin-audit.admin.categories.settings')}</option>
            <option value="extensions">{app.translator.trans('bendary-admin-audit.admin.categories.extensions')}</option>
          </select>
        </div>
      </div>
    );
  }
}
