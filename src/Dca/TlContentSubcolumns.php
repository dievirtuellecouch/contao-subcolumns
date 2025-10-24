<?php

namespace Websailing\SubcolumnsBundle\Dca;

use Contao\Database;
use Contao\DataContainer;
use Contao\Controller;
use Contao\Widget as CtlWidget;
use Contao\TextField as CtlTextField;
use Contao\StringUtil;

class TlContentSubcolumns
{
    public function getAllTypes(): array
    {
        // Read from TL_SUBCL['flex'] sets
        $sets = $GLOBALS['TL_SUBCL']['flex']['sets'] ?? [];
        return array_keys($sets);
    }

    public function createPalette(DataContainer $dc): void
    {
        // Palette already defined; could be adapted per global config if needed
    }

    public function scUpdate(DataContainer $dc): void
    {
        if (!$dc->activeRecord || $dc->activeRecord->type !== 'colsetStart') return;
        $type = (string) $dc->activeRecord->sc_type;
        if ($type === '') return;

        $db = Database::getInstance();
        $pid = (int) $dc->activeRecord->pid;
        $sortingStart = (int) $dc->activeRecord->sorting;
        $name = (string) ($dc->activeRecord->sc_name ?: ('colset.'.$dc->id));
        $gap  = (string) $dc->activeRecord->sc_gap;
        $equal= (string) $dc->activeRecord->sc_equalize;

        $cols = array_filter(array_map('trim', explode('x', $type)), 'strlen');
        $colCount = max(2, count($cols));

        $childs = [];
        if ($dc->activeRecord->sc_childs) {
            $tmp = @unserialize($dc->activeRecord->sc_childs) ?: [];
            if (is_array($tmp)) { $childs = array_map('intval', $tmp); }
        }

        $moveRows = function(int $amount) use ($db, $pid, $sortingStart) {
            $db->prepare('UPDATE tl_content SET sorting = sorting + ? WHERE pid=? AND sorting > ?')
               ->execute($amount, $pid, $sortingStart);
        };

        if (!$childs) {
            $moveRows(64 * ($colCount));
            for ($i=1; $i <= $colCount-1; $i++) {
                $set = [
                    'pid'=>$pid,
                    'tstamp'=>time(),
                    'sorting'=>$sortingStart+($i+1)*64,
                    'type'=>'colsetPart',
                    'cssID'=>'',
                    'sc_parent'=>$dc->id,
                    'sc_sortid'=>$i,
                    'sc_type'=>$type,
                ];
                $id = $db->prepare('INSERT INTO tl_content %s')->set($set)->execute()->insertId;
                $childs[] = (int)$id;
            }
            $end = [
                'pid'=>$pid,
                'tstamp'=>time(),
                'sorting'=>$sortingStart+($colCount+1)*64,
                'type'=>'colsetEnd',
                'cssID'=>'',
                'sc_parent'=>$dc->id,
                'sc_sortid'=>$colCount,
                'sc_type'=>$type,
            ];
            $endId = $db->prepare('INSERT INTO tl_content %s')->set($end)->execute()->insertId;
            $childs[] = (int)$endId;
            $db->prepare('UPDATE tl_content %s WHERE id=?')->set(['sc_childs'=>serialize($childs),'sc_name'=>$name])->execute($dc->id);
            return;
        }

        // adjust counts
        if ($colCount - 1 > count($childs) - 1) {
            // Number of additional parts we need to reach target (excluding END)
            $need = ($colCount - 1) - (count($childs) - 1);
            $endId = (int) end($childs);
            $end = $db->prepare('SELECT sorting,sc_sortid FROM tl_content WHERE id=?')->execute($endId);
            $db->prepare('UPDATE tl_content SET sorting = sorting + ? WHERE pid=? AND sorting > ?')
               ->execute(64*$need, $pid, (int)$end->sorting);
            // Convert current end to part and fix sortid
            $db->prepare('UPDATE tl_content %s WHERE id=?')->set(['type'=>'colsetPart','sc_type'=>$type,'sc_sortid'=>max(1,(int)$end->sc_sortid - 1)])->execute($endId);
            $sorting = (int)$end->sorting;
            $sortid  = max(1,(int)$end->sc_sortid - 1);
            // We already turned END into a PART, so only insert (need-1) additional parts
            for ($i=0; $i<max(0, $need-1); $i++) {
                $sorting += 64; $sortid++;
                $ins = ['pid'=>$pid,'tstamp'=>time(),'sorting'=>$sorting,'type'=>'colsetPart','sc_parent'=>$dc->id,'sc_sortid'=>$sortid,'sc_type'=>$type];
                $new = $db->prepare('INSERT INTO tl_content %s')->set($ins)->execute()->insertId;
                $childs[] = (int)$new;
            }
            $insEnd = ['pid'=>$pid,'tstamp'=>time(),'sorting'=>$sorting+64,'type'=>'colsetEnd','sc_parent'=>$dc->id,'sc_sortid'=>$colCount,'sc_type'=>$type];
            $newEnd = $db->prepare('INSERT INTO tl_content %s')->set($insEnd)->execute()->insertId;
            $childs[] = (int)$newEnd;
        } elseif ($colCount - 1 < count($childs) - 1) {
            // Remove extra parts, keep end
            $toRemove = (count($childs) - 1) - ($colCount - 1);
            // Determine current parts (exclude last end)
            $current = $db->prepare('SELECT id,type FROM tl_content WHERE sc_parent=? ORDER BY sorting')->execute($dc->id)->fetchAllAssoc();
            $partIds = [];
            $endId = null;
            foreach ($current as $row) {
                if ($row['type'] === 'colsetPart') { $partIds[] = (int)$row['id']; }
                elseif ($row['type'] === 'colsetEnd') { $endId = (int)$row['id']; }
            }
            // Remove from the end-most parts
            for ($i=0; $i<$toRemove && !empty($partIds); $i++) {
                $rm = array_pop($partIds);
                $db->prepare('DELETE FROM tl_content WHERE id=?')->execute($rm);
                // Also pop from cached list if present
                $key = array_search($rm, $childs, true);
                if ($key !== false) { unset($childs[$key]); }
            }
        }
        // Normalize children: ensure sequential sc_sortid for parts and end=colCount, set sc_type
        $ordered = $db->prepare('SELECT id,type FROM tl_content WHERE sc_parent=? ORDER BY sorting')->execute($dc->id)->fetchAllAssoc();
        $ids = [];
        $p = 0;
        foreach ($ordered as $row) {
            if ($row['type'] === 'colsetPart') {
                $p++;
                $db->prepare('UPDATE tl_content %s WHERE id=?')->set(['sc_sortid'=>$p,'sc_type'=>$type])->execute((int)$row['id']);
                $ids[] = (int)$row['id'];
            }
        }
        // End element last
        foreach ($ordered as $row) {
            if ($row['type'] === 'colsetEnd') {
                $db->prepare('UPDATE tl_content %s WHERE id=?')->set(['sc_sortid'=>$colCount,'sc_type'=>$type])->execute((int)$row['id']);
                $ids[] = (int)$row['id'];
                break;
            }
        }
        if (!empty($ids)) {
            $db->prepare('UPDATE tl_content %s WHERE id=?')->set(['sc_childs'=>serialize($ids),'sc_name'=>$name])->execute($dc->id);
        }
    }

    public function scDelete(DataContainer $dc): void
    {
        if (!$dc->activeRecord || $dc->activeRecord->type !== 'colsetStart' || !$dc->activeRecord->sc_childs) return;
        $childs = @unserialize($dc->activeRecord->sc_childs) ?: [];
        if (!$childs) return;
        Database::getInstance()->prepare('DELETE FROM tl_content WHERE id IN ('.implode(',', array_map('intval',$childs)).')')->execute();
    }

    /** Combine wrap id/class into a single multi-field UI */
    public function loadWrapAttrs($value, DataContainer $dc): array
    {
        $id = (string)($dc->activeRecord->sc_wrap_id ?? '');
        $cls = (string)($dc->activeRecord->sc_wrap_class ?? '');
        return [$id, $cls];
    }

    public function saveWrapAttrs($value, DataContainer $dc): string
    {
        $arr = is_array($value) ? $value : StringUtil::deserialize((string)$value, true);
        $id  = (string)($arr[0] ?? '');
        $cls = (string)($arr[1] ?? '');
        Database::getInstance()->prepare('UPDATE tl_content %s WHERE id=?')->set([
            'sc_wrap_id' => $id,
            'sc_wrap_class' => $cls,
        ])->execute($dc->id);
        // Return empty since this is a virtual field
        return '';
    }

    /**
     * Render the sc_wrap_id field wrapped in a tl_text_field div.
     */
    public function renderWrapIdInput(DataContainer $dc): string
    {
        $table = 'tl_content';
        $name  = 'sc_wrap_id';
        $value = '';
        if ($dc->activeRecord && property_exists($dc->activeRecord, $name)) {
            $value = (string) $dc->activeRecord->$name;
        }
        $arr = $GLOBALS['TL_DCA'][$table]['fields'][$name] ?? [];
        // Prevent recursion: do not include this callback when creating the widget
        if (isset($arr['input_field_callback'])) {
            unset($arr['input_field_callback']);
        }
        $attributes = CtlWidget::getAttributesFromDca($arr, $name, $value, $name, $table, $dc);
        $widget = new CtlTextField($attributes);
        return '<div class="tl_text_field">'.$widget->parse().'</div>';
    }
}
