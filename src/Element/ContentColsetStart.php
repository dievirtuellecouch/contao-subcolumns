<?php

namespace Websailing\SubcolumnsBundle\Element;

use Contao\ContentElement;

class ContentColsetStart extends ContentElement
{
    protected $strTemplate = 'ce_colsetStart';

    public function generate(): string
    {
        if ((defined('TL_MODE') && TL_MODE === 'BE') || (defined('TL_SCRIPT') && strpos((string)TL_SCRIPT, 'contao') !== false)) {
            $objTemplate = new \BackendTemplate('be_wildcard');
            $objTemplate->wildcard = '### Spaltenset Anfang ###';
            $objTemplate->title = (string) ($this->headline ?: ($this->sc_name ?: ''));
            $objTemplate->id = $this->id;
            $objTemplate->link = 'ID '.$this->id;
            $objTemplate->href = ''; // let Contao default links render
            return $objTemplate->parse();
        }
        return parent::generate();
    }

    protected function compile(): void
    {
        $setKey = $GLOBALS['TL_CONFIG']['subcolumns'] ?? 'flex';
        $sets   = $GLOBALS['TL_SUBCL'][$setKey]['sets'] ?? [];
        $scclass= $GLOBALS['TL_SUBCL'][$setKey]['scclass'] ?? 'sc-flex';
        $eqCls  = $GLOBALS['TL_SUBCL'][$setKey]['equalize'] ?? 'equalize';
        $equal  = ($this->sc_equalize ?? '') ? (' '.$eqCls) : '';
        $type   = trim((string) ($this->sc_type ?? '')) ?: '50x50';
        $map    = $sets[$type] ?? [];
        $colcount = !empty($map) ? count($map) : max(2, count(array_filter(array_map('trim', explode('x', $type)), 'strlen')));
        // Add colcount_N and flag has-gap when gap is enabled
        $gapCls = !empty($this->sc_gapuse) ? ' has-gap' : '';
        $this->Template->sc_wrapper_class = trim($scclass.' colcount_'.$colcount.$gapCls.$equal);

        // Ensure CSS for the set is loaded (similar to form start)
        $css = $GLOBALS['TL_SUBCL'][$setKey]['files']['css'] ?? '';
        if ($css) {
            $GLOBALS['TL_CSS'][] = $css.'|static';
        }

        // Gap handling (default 20px if enabled and empty)
        $gapInt = null;
        if (!empty($this->sc_gapuse)) {
            $gapVal = trim((string)($this->sc_gap ?? ''));
            if ($gapVal === '') {
                $gapVal = (string)($GLOBALS['TL_CONFIG']['subcolumns_gapdefault'] ?? '20');
            }
            $gapInt = (int)$gapVal;
            if ($gapInt < 0) { $gapInt = 0; }
            $this->Template->wrapper_style = '--sc-gap: '.$gapInt.'px; gap: '.$gapInt.'px';
        }

        if (!empty($map) && is_array($map[0] ?? null)) {
            $this->Template->col_class = implode(' ', array_filter($map[0]));
        } else {
            $this->Template->col_class = 'sc-flex__col sc-flex__col--1-2';
        }

        // No inline widths: handled via classes and CSS only
    }
}
