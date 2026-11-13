import { createRoot } from 'react-dom/client';
import TaxDashboard from './customer/TaxDashboard';

const root = document.getElementById('customer-tax-root');

if (root) {
    createRoot(root).render(<TaxDashboard appName={root.dataset.appName || 'VTMS'} logo={root.dataset.logo || ''} />);
}
