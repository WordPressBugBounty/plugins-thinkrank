/**
 * Supported migration source plugins — slug → display name + logo.
 *
 * Mirrors the source list in
 * includes/admin/importers/class-import-detector.php (keep in sync). Logos are
 * reused from the setup-wizard migration assets so the Migration page and the
 * onboarding wizard stay visually consistent.
 *
 * @package
 * @since 2.0.0
 */

import yoastLogo from '../../setup-wizard/assets/migration/yoast.png';
import rankMathLogo from '../../setup-wizard/assets/migration/rankmath.png';
import aioseoLogo from '../../setup-wizard/assets/migration/aioseo.png';
import seoPressLogo from '../../setup-wizard/assets/migration/seopress.svg';
import squirrlyLogo from '../../setup-wizard/assets/migration/squirrly.svg';
import slimSeoLogo from '../../setup-wizard/assets/migration/slimseo.svg';
import sureRankLogo from '../../setup-wizard/assets/migration/surerank.svg';

/** Slug → logo asset, for looking up a detected plugin's mark by slug. */
export const PLATFORM_LOGOS = {
	yoast: yoastLogo,
	rankmath: rankMathLogo,
	seopress: seoPressLogo,
	aioseo: aioseoLogo,
	squirrly: squirrlyLogo,
	slimseo: slimSeoLogo,
	surerank: sureRankLogo,
};

/** Full supported roster, in display order, for the empty-state showcase. */
export const SUPPORTED_PLATFORMS = [
	{ slug: 'yoast', name: 'Yoast SEO', logo: yoastLogo },
	{ slug: 'rankmath', name: 'Rank Math', logo: rankMathLogo },
	{ slug: 'seopress', name: 'SEOPress', logo: seoPressLogo },
	{ slug: 'aioseo', name: 'All in One SEO', logo: aioseoLogo },
	{ slug: 'squirrly', name: 'Squirrly SEO', logo: squirrlyLogo },
	{ slug: 'slimseo', name: 'Slim SEO', logo: slimSeoLogo },
	{ slug: 'surerank', name: 'SureRank', logo: sureRankLogo },
];
