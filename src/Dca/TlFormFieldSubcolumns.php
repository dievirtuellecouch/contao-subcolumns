<?php

namespace Websailing\SubcolumnsBundle\Dca;

use Contao\DataContainer;
use Contao\Database;
use Contao\StringUtil;

class TlFormFieldSubcolumns
{
    /**
     * Return a set of common column splits similar to legacy TL_SUBCL
     */
    public function getAllTypes(): array
    {
        return [
            '50x50', '33x33x33', '25x25x25x25', '66x33', '33x66', '75x25', '25x75', '70x30', '30x70',
            '40x60', '60x40', '20x40x40', '40x20x40', '40x40x20',
        ];
    }

    /** Create/update children on submit of formcolstart */
    public function onSubmit(DataContainer $dc): void
    {
        if (!$dc->activeRecord || $dc->activeRecord->type !== 'formcolstart') {
            return;
        }
        $type = (string) $dc->activeRecord->fsc_type;
        if ($type === '') { return; }
        $db = Database::getInstance();
        $pid = (int) $dc->activeRecord->pid;
        $sortingStart = (int) $dc->activeRecord->sorting;
        $name = (string) ($dc->activeRecord->fsc_name ?: ('colset.'.$dc->id));
        $gapuse = (string) $dc->activeRecord->fsc_gapuse;
        $gap = (string) $dc->activeRecord->fsc_gap;

        // Determine column count from type like "33x33x33"
        $cols = array_filter(array_map('trim', explode('x', $type)), 'strlen');
        $colCount = max(2, count($cols));

        // Existing children
        $childs = [];
        if ($dc->activeRecord->fsc_childs) {
            $tmp = @unserialize($dc->activeRecord->fsc_childs) ?: [];
            if (is_array($tmp)) { $childs = array_map('intval', $tmp); }
        }

        // Helper to move following rows (keep order)
        $moveRows = function(int $amount) use ($db, $pid, $sortingStart) {
            $db->prepare('UPDATE tl_form_field SET sorting = sorting + ? WHERE pid=? AND sorting > ?')
               ->execute($amount, $pid, $sortingStart);
        };

        // Create all if empty
        if (!$childs) {
            // space for parts+end
            $moveRows(64 * ($colCount));
            // Insert parts
            for ($i = 1; $i <= $colCount - 1; $i++) {
                $set = [
                    'pid' => $pid,
                    'tstamp' => time(),
                    'sorting' => $sortingStart + ($i+1)*64,
                    'type' => 'formcolpart',
                    'label' => '',
                    'fsc_name' => $name.'-Part-'.$i,
                    'fsc_type' => $type,
                    'fsc_parent' => $dc->id,
                    'fsc_sortid' => $i,
                    'fsc_gapuse' => $gapuse,
                    'fsc_gap' => $gap,
                ];
                $insertId = $db->prepare('INSERT INTO tl_form_field %s')->set($set)->execute()->insertId;
                $childs[] = (int) $insertId;
            }
            // Insert end
            $set = [
                'pid' => $pid,
                'tstamp' => time(),
                'sorting' => $sortingStart + ($colCount+1)*64,
                'type' => 'formcolend',
                'label' => '',
                'fsc_name' => $name.'-End',
                'fsc_type' => $type,
                'fsc_parent' => $dc->id,
                'fsc_sortid' => $colCount,
                'fsc_gapuse' => $gapuse,
                'fsc_gap' => $gap,
            ];
            $endId = $db->prepare('INSERT INTO tl_form_field %s')->set($set)->execute()->insertId;
            $childs[] = (int) $endId;

            // Save children list
            $db->prepare('UPDATE tl_form_field %s WHERE id=?')
               ->set(['fsc_childs' => serialize($childs), 'fsc_name' => $name])
               ->execute($dc->id);
            return;
        }

        // Update existing: adjust count
        // Load last child (end)
        $endId = (int) end($childs);
        $end = $db->prepare('SELECT id,sorting,fsc_sortid FROM tl_form_field WHERE id=?')->execute($endId);
        if ($colCount - 1 > count($childs) - 1) {
            // Need more parts (excluding END)
            $need = ($colCount - 1) - (count($childs) - 1);
            // Move rows after end
            $db->prepare('UPDATE tl_form_field SET sorting = sorting + ? WHERE pid=? AND sorting > ?')
               ->execute(64*$need, $pid, (int)$end->sorting);
            // Convert current end to part
            $db->prepare('UPDATE tl_form_field %s WHERE id=?')
               ->set(['type'=>'formcolpart','fsc_name'=>$name.'-Part-'.(count($childs)),'fsc_sortid'=>max(1,(int)$end->fsc_sortid - 1)])
               ->execute($endId);
            // Insert new parts
            $sorting = (int)$end->sorting;
            $sortid  = max(1,(int)$end->fsc_sortid - 1);
            // END is now a PART; only insert (need-1) more parts
            for ($i=0;$i<max(0,$need-1);$i++){
                $sorting += 64; $sortid++;
                $ins = [
                    'pid'=>$pid,'tstamp'=>time(),'sorting'=>$sorting,'type'=>'formcolpart','label'=>'',
                    'fsc_name'=>$name.'-Part-'.(count($childs)+$i),'fsc_type'=>$type,'fsc_parent'=>$dc->id,
                    'fsc_sortid'=> $sortid,'fsc_gapuse'=>$gapuse,'fsc_gap'=>$gap
                ];
                $newId = $db->prepare('INSERT INTO tl_form_field %s')->set($ins)->execute()->insertId;
                $childs[] = (int)$newId;
            }
            // Insert new end
            $insEnd = [
                'pid'=>$pid,'tstamp'=>time(),'sorting'=>$sorting+64,'type'=>'formcolend','label'=>'',
                'fsc_name'=>$name.'-End','fsc_type'=>$type,'fsc_parent'=>$dc->id,
                'fsc_sortid'=> $colCount,'fsc_gapuse'=>$gapuse,'fsc_gap'=>$gap
            ];
            $newEnd = $db->prepare('INSERT INTO tl_form_field %s')->set($insEnd)->execute()->insertId;
            $childs[] = (int)$newEnd;
        } elseif ($colCount - 1 < count($childs) - 1) {
            // Remove extra parts (keep end)
            $toRemove = (count($childs) - 1) - ($colCount - 1);
            for ($i=0; $i<$toRemove; $i++) {
                $rm = array_pop($childs);
                // last pop may be the end; ensure we remove parts first
                if ($rm == $endId) { $rm = array_pop($childs); $endId = (int) end($childs); }
                $db->prepare('DELETE FROM tl_form_field WHERE id=?')->execute($rm);
            }
        }

        // Normalize children: sequential sortid for parts and end=colCount, and update attributes
        $ordered = $db->prepare('SELECT id,type FROM tl_form_field WHERE fsc_parent=? ORDER BY sorting')->execute($dc->id)->fetchAllAssoc();
        $ids = [];
        $p = 0;
        foreach ($ordered as $row) {
            if ($row['type'] === 'formcolpart') {
                $p++;
                $db->prepare('UPDATE tl_form_field %s WHERE id=?')
                   ->set(['fsc_type'=>$type,'fsc_gapuse'=>$gapuse,'fsc_gap'=>$gap,'fsc_sortid'=>$p])
                   ->execute((int)$row['id']);
                $ids[] = (int)$row['id'];
            }
        }
        foreach ($ordered as $row) {
            if ($row['type'] === 'formcolend') {
                $db->prepare('UPDATE tl_form_field %s WHERE id=?')
                   ->set(['fsc_type'=>$type,'fsc_gapuse'=>$gapuse,'fsc_gap'=>$gap,'fsc_sortid'=>$colCount])
                   ->execute((int)$row['id']);
                $ids[] = (int)$row['id'];
                break;
            }
        }

        // Persist list
        if (!empty($ids)) {
            $db->prepare('UPDATE tl_form_field %s WHERE id=?')->set(['fsc_childs'=>serialize($ids),'fsc_name'=>$name])->execute($dc->id);
        }
    }

    public function onDelete(DataContainer $dc): void
    {
        if (!$dc->activeRecord || $dc->activeRecord->type !== 'formcolstart' || !$dc->activeRecord->fsc_childs) return;
        $childs = @unserialize($dc->activeRecord->fsc_childs) ?: [];
        if (!$childs) return;
        Database::getInstance()->prepare('DELETE FROM tl_form_field WHERE id IN ('.implode(',', array_map('intval',$childs)).')')->execute();
    }

    /** Combine wrap id/class into a single multi-field UI */
    public function loadWrapAttrs($value, DataContainer $dc): array
    {
        $id = (string)($dc->activeRecord->fsc_wrap_id ?? '');
        $cls = (string)($dc->activeRecord->fsc_wrap_class ?? '');
        return [$id, $cls];
    }

    public function saveWrapAttrs($value, DataContainer $dc): string
    {
        $arr = is_array($value) ? $value : StringUtil::deserialize((string)$value, true);
        $id  = (string)($arr[0] ?? '');
        $cls = (string)($arr[1] ?? '');
        Database::getInstance()->prepare('UPDATE tl_form_field %s WHERE id=?')->set([
            'fsc_wrap_id' => $id,
            'fsc_wrap_class' => $cls,
        ])->execute($dc->id);
        return '';
    }
}
