/**
 * Migration Page
 *
 * Bring SEO data in from another plugin (Yoast, Rank Math, SEOPress, AIOSEO, Squirrly, Slim SEO).
 * Thin page wrapper that renders the import half of ImportExportTab; ThinkRank's
 * own export/restore lives on its own page (see ImportExport.js).
 *
 * @package
 * @since 2.2.0
 */

import { ImportExportTab } from '../import-export/ImportExportTab';

const Migration = () => <ImportExportTab section="import" />;

export default Migration;
