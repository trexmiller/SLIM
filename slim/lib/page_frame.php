<?php
declare(strict_types=1);

/**
 * Small shared page-framing helpers. These intentionally avoid a template
 * framework; they centralize only the footer/help pieces that were repeatedly
 * copied across SLIM pages.
 */
function slim_admin_page_footer(bool $includeHelp=true): void {
    echo '<div class="site-footer slim-version-footer">'
        . htmlspecialchars(slim_footer_text(),ENT_QUOTES,'UTF-8')
        . '</div>';
    if($includeHelp) {
        echo slim_admin_help_assets();
    }
}

function slim_public_page_footer(): void {
    echo '<div class="slim-version-footer">'
        . htmlspecialchars(slim_footer_text(),ENT_QUOTES,'UTF-8')
        . '</div>';
}
