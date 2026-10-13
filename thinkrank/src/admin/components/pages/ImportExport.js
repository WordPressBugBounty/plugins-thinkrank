/**
 * Import/Export Page
 *
 * ThinkRank's own data, out to a file and back. Thin page wrapper that renders
 * the export half of ImportExportTab; the import-from-another-plugin half lives
 * on its own page (see Migration.js).
 *
 * @package
 * @since 2.0.0
 */

import { ImportExportTab } from '../import-export/ImportExportTab';

const ImportExport = () => <ImportExportTab section="export" />;

export default ImportExport;
