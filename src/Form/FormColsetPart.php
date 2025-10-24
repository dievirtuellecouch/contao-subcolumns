<?php

namespace Websailing\SubcolumnsBundle\Form;

use Contao\Widget;
use Contao\Database;

class FormColsetPart extends Widget
{
    protected $strTemplate = 'form_formcolpart';
    public function validate(): void {}
    public function generate(): string {
        if (defined('TL_MODE') && TL_MODE === 'BE') {
            return '';
        }
        $setKey = 'flex';
        $sets   = $GLOBALS['TL_SUBCL'][$setKey]['sets'] ?? [];
        $inside = (bool)($GLOBALS['TL_SUBCL'][$setKey]['inside'] ?? false);
        $type   = trim((string) ($this->fsc_type ?? '')) ?: '50x50';
        $map    = $sets[$type] ?? [];
        $idx    = (int) ($this->fsc_sortid ?? 1);
        $colIdx = $idx; // part index 1 => second column
        $tpl = $this->getTemplateObject();
        $tpl->useInside = $inside;
        $tpl->inside_class = !empty($map[$colIdx][1]) ? $map[$colIdx][1] : '';
        $baseCol = !empty($map[$colIdx][0]) ? $map[$colIdx][0] : 'sc-flex__col sc-flex__col--1-2';
        $tpl->col_class = trim($baseCol);
        // No inline widths: handled via classes and CSS only
        return $tpl->parse();
    }
}
