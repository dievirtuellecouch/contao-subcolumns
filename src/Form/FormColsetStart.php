<?php

namespace Websailing\SubcolumnsBundle\Form;

use Contao\Widget;
use Contao\StringUtil;

class FormColsetStart extends Widget
{
    protected $strTemplate = 'form_formcolstart';
    public function validate(): void {}
    public function generate(): string {
        if (defined('TL_MODE') && TL_MODE === 'BE') {
            return '';
        }
        $setKey  = 'flex';
        $sets    = $GLOBALS['TL_SUBCL'][$setKey]['sets'] ?? [];
        $scclass = $GLOBALS['TL_SUBCL'][$setKey]['scclass'] ?? 'sc-flex';
        $inside  = (bool)($GLOBALS['TL_SUBCL'][$setKey]['inside'] ?? false);
        $eqClass = $GLOBALS['TL_SUBCL'][$setKey]['equalize'] ?? '';
        $type    = trim((string) ($this->fsc_type ?? '')) ?: '50x50';
        $map     = $sets[$type] ?? [];

        // Ensure CSS for the set is loaded
        $css = $GLOBALS['TL_SUBCL'][$setKey]['files']['css'] ?? '';
        if ($css) {
            $GLOBALS['TL_CSS'][] = $css.'|static';
        }

        $eq      = (!empty($this->fsc_equalize) && !empty($eqClass)) ? ($eqClass.' ') : '';
        $hasGap  = !empty($this->fsc_gapuse) ? ' has-gap' : '';
        $wrapper = trim($eq.($scclass ?: 'subcolumns').' colcount_'.(is_array($map)?count($map):2).$hasGap.' '.$setKey.' col-'.$type.(($this->class ?? '') ? ' '.$this->class : ''));

        $tpl = $this->getTemplateObject();
        $tpl->sc_wrapper_class = $wrapper;
        // Gap handling: use fsc_gap or fallback to subcolumns_gapdefault (or 12)
        if (!empty($this->fsc_gapuse)) {
            $gapVal = trim((string)($this->fsc_gap ?? ''));
            if ($gapVal === '') {
                $gapVal = (string)($GLOBALS['TL_CONFIG']['subcolumns_gapdefault'] ?? '20');
            }
            $gapInt = (int)$gapVal;
            if ($gapInt < 0) { $gapInt = 0; }
            $tpl->wrapper_style = '--sc-gap: '.$gapInt.'px; gap: '.$gapInt.'px';
        }
        $tpl->useInside = $inside;
        $tpl->inside_class = !empty($map[0][1]) ? $map[0][1] : '';
        $baseCol = !empty($map[0][0]) ? $map[0][0] : 'sc-flex__col sc-flex__col--1-2';
        $tpl->col_class = trim($baseCol);
        // No inline widths: handled via classes and CSS only
        return $tpl->parse();
    }
}
