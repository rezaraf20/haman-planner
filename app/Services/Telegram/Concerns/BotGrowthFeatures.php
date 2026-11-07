<?php
declare(strict_types=1);

namespace App\Services\Telegram\Concerns;

use App\Models\FocusSession;
use App\Models\Tag;
use App\Models\Task;
use App\Services\Planner\EstimateService;
use App\Services\Planner\FocusService;
use App\Services\Planner\RitualService;
use App\Services\Planner\SchedulingService;
use App\Services\Planner\TaskBreakdownService;
use App\Services\Planner\TaskFilter;
use App\Support\LocalDate;
use Illuminate\Validation\ValidationException;

/**
 * Bot screens for the features added in phases 1–4: morning planning / evening shutdown, focus
 * timer, smart lists and tags, breaking a task into steps, estimates from history.
 * Same rules as the web app: every action goes through the same services (ownership, plan
 * limits, validation) and nothing is changed until the user presses the confirming button.
 */
trait BotGrowthFeatures
{
    private function g(string $key, array $replace = []): string
    {
        return (string) __('botx.'.$key, $replace);
    }

    private function n(int|float $v): string
    {
        return LocalDate::number($v);
    }

    private function mins(int $m): string
    {
        return SchedulingService::duration(max(0, $m), $this->user->preferredLocale());
    }

    private function short(string $s, int $len = 34): string
    {
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1).'…' : $s;
    }

    /** Show the first validation message instead of failing. */
    private function guarded(string|int $c, ?int $m, string $backTo, callable $fn): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->show($c, $m, '⚠️ '.collect($e->errors())->flatten()->first(), [$this->back($backTo)]);
        } catch (\App\Exceptions\PlanLimitReached $e) {
            $this->show($c, $m, '⚠️ '.$e->getMessage(), [$this->back($backTo)]);
        }
    }

    // ================================================================== rituals

    private function ritualsMenu(string|int $c, ?int $m, ?string $notice = null): void
    {
        $this->clearState($c);
        $svc = app(RitualService::class);
        $day = $svc->today($this->user);
        $mo = $svc->find($this->user, $day, 'morning');
        $ev = $svc->find($this->user, $day, 'evening');
        $st = fn ($r) => $r ? $this->g('rit.status_'.$r->status) : $this->g('rit.status_none');
        $text = ($notice ? $notice."\n\n" : '').$this->g('rit.title')."\n\n"
            .'🌅 '.$this->g('rit.morning').': '.$st($mo)."\n".'🌙 '.$this->g('rit.evening').': '.$st($ev)
            .($this->user->preference('rituals_enabled') === false ? "\n\n".$this->g('rit.disabled_note') : '');
        $this->show($c, $m, $text, [
            [$this->btn('🌅 '.$this->g('rit.start_morning'), 'rm'), $this->btn('🌙 '.$this->g('rit.start_evening'), 're')],
            $this->back('home'),
        ]);
    }

    private function ritualDraft(string|int $c, string $kind, ?int $m): array
    {
        $s = $this->state($c);
        if (($s['mode'] ?? null) === 'ritual' && ($s['kind'] ?? null) === $kind) {
            return $s;
        }
        $svc = app(RitualService::class);
        $d = $kind === 'morning' ? $svc->morning($this->user) : $svc->evening($this->user);
        $r = ($d['ritual']['status'] ?? null) === 'completed' ? $d['ritual'] : [];
        $s = ['mode' => 'ritual', 'kind' => $kind, 'mid' => $m, 'moves' => [], 'awaiting' => null,
            'energy' => $r['energy'] ?? null, 'intention' => $r['intention'] ?? null, 'mood' => $r['mood'] ?? null, 'wins' => $r['wins'] ?? null,
            'picks' => array_slice($kind === 'morning' ? $d['suggested_top'] : $d['suggested_tomorrow'], 0, 3)];
        $this->putState($c, $s);
        return $this->state($c);
    }

    private function scaleRow(string $prefix, ?int $value): array
    {
        $row = [];
        for ($i = 1; $i <= 5; $i++) {
            $row[] = $this->btn(($value === $i ? '● ' : '').$this->n($i), $prefix.':'.$i);
        }
        return $row;
    }

    private function morningScreen(string|int $c, ?int $m): void
    {
        $s = $this->ritualDraft($c, 'morning', $m);
        $d = app(RitualService::class)->morning($this->user);
        $scale = (array) __('rituals.energy_scale');
        $lines = [$this->g('rit.morning_title'), '', __('rituals.yesterday_line', ['count' => $this->n($d['yesterday']['completed']), 'time' => $d['yesterday']['logged_text']]),
            $this->g('rit.energy').': '.($s['energy'] ? $this->n($s['energy']).' — '.($scale[$s['energy'] - 1] ?? '') : '—')];
        if ($s['intention']) $lines[] = __('rituals.intention_label').': '.$s['intention'];
        $kb = [$this->scaleRow('rme', $s['energy'])];

        $left = array_slice($d['leftovers'], 0, 5);
        if ($left) {
            $lines[] = '';
            $lines[] = '📥 '.$this->g('rit.leftovers');
            foreach ($left as $i => $x) {
                $a = $s['moves'][$x['id']] ?? 'keep';
                $lines[] = $this->n($i + 1).'. '.$x['title'].' — '.$this->g('rit.act_'.$a);
                $kb[] = [
                    $this->btn($this->n($i + 1).' ▸ '.($a === 'today' ? '✓ ' : '').__('rituals.act_today'), 'rmm:'.$x['id'].':t'),
                    $this->btn(($a === 'tomorrow' ? '✓ ' : '').__('rituals.act_tomorrow'), 'rmm:'.$x['id'].':n'),
                    $this->btn(($a === 'keep' ? '✓ ' : '').__('rituals.act_keep'), 'rmm:'.$x['id'].':k'),
                ];
            }
        }
        $lines[] = '';
        $lines[] = '🎯 '.$this->g('rit.pick_top3');
        foreach (array_slice($d['candidates'], 0, 8) as $x) {
            $on = in_array($x['id'], $s['picks'], true);
            $kb[] = [$this->btn(($on ? '☑️ ' : '⬜️ ').$this->short($x['title']), 'rmp:'.$x['id'])];
        }
        $cap = $d['capacity'];
        if ($cap['working']) {
            $lines[] = '';
            $lines[] = '⏳ '.__('rituals.capacity_line', ['usable' => $cap['usable_text'], 'committed' => $cap['committed_text']]);
            if ($cap['over']) $lines[] = '⚠️ '.__('rituals.capacity_over');
        }
        $kb[] = [$this->btn('✍️ '.$this->g('rit.write_intention'), 'rmi')];
        $kb[] = [$this->btn('✅ '.__('rituals.save_morning'), 'rms'), $this->btn(__('rituals.skip_today'), 'rsk:morning')];
        $kb[] = $this->back('rit');
        $this->show($c, $m, implode("\n", $lines), $kb);
    }

    private function eveningScreen(string|int $c, ?int $m): void
    {
        $s = $this->ritualDraft($c, 'evening', $m);
        $d = app(RitualService::class)->evening($this->user);
        $sum = $d['summary'];
        $scale = (array) __('rituals.mood_scale');
        $lines = [$this->g('rit.evening_title'), '',
            '✅ '.__('rituals.sum_completed').': '.$this->n($sum['completed']).' · ⏱ '.__('rituals.sum_logged').': '.$sum['logged_text'].' · 🎯 '.__('rituals.sum_focus').': '.$sum['focus_text']];
        if ($sum['habits_due']) $lines[] = '🌱 '.__('rituals.sum_habits').': '.__('rituals.of', ['done' => $this->n($sum['habits_done']), 'total' => $this->n($sum['habits_due'])]);
        if ($sum['top_total']) $lines[] = '🏁 '.__('rituals.sum_top3').': '.__('rituals.of', ['done' => $this->n($sum['top_done']), 'total' => $this->n($sum['top_total'])]);
        foreach (array_slice($d['completed'], 0, 6) as $x) $lines[] = '  ✓ '.$x['title'];
        $lines[] = '';
        $lines[] = $this->g('rit.mood').': '.($s['mood'] ? $this->n($s['mood']).' — '.($scale[$s['mood'] - 1] ?? '') : '—');
        if ($s['wins']) $lines[] = '🏆 '.$s['wins'];
        $kb = [$this->scaleRow('rem', $s['mood'])];

        $open = array_slice($d['unfinished'], 0, 5);
        if ($open) {
            $lines[] = '';
            $lines[] = '📌 '.$this->g('rit.unfinished');
            foreach ($open as $i => $x) {
                $a = $s['moves'][$x['id']] ?? 'keep';
                $lines[] = $this->n($i + 1).'. '.$x['title'].' — '.$this->g('rit.act_'.$a);
                $kb[] = [
                    $this->btn($this->n($i + 1).' ▸ '.($a === 'tomorrow' ? '✓ ' : '').__('rituals.act_tomorrow'), 'rmv:'.$x['id'].':n'),
                    $this->btn(($a === 'keep' ? '✓ ' : '').__('rituals.act_keep'), 'rmv:'.$x['id'].':k'),
                ];
            }
        }
        $lines[] = '';
        $lines[] = '🗓 '.$this->g('rit.pick_tomorrow');
        foreach (array_slice($d['candidates'], 0, 6) as $x) {
            $on = in_array($x['id'], $s['picks'], true);
            $kb[] = [$this->btn(($on ? '☑️ ' : '⬜️ ').$this->short($x['title']), 'rep:'.$x['id'])];
        }
        $kb[] = [$this->btn('🏆 '.$this->g('rit.write_wins'), 'rew')];
        $kb[] = [$this->btn('✅ '.__('rituals.save_evening'), 'res'), $this->btn(__('rituals.skip_today'), 'rsk:evening')];
        $kb[] = $this->back('rit');
        $this->show($c, $m, implode("\n", $lines), $kb);
    }

    /** Callback updates of the ritual draft (nothing is saved until rms / res). */
    private function ritualUpdate(string|int $c, ?int $m, string $kind, string $field, int $id, string $value = ''): void
    {
        $s = $this->ritualDraft($c, $kind, $m);
        if ($field === 'energy' || $field === 'mood') {
            $s[$field] = $id >= 1 && $id <= 5 ? $id : null;
        } elseif ($field === 'move' && $id > 0) {
            $s['moves'][$id] = ['t' => 'today', 'n' => 'tomorrow', 'k' => 'keep'][$value] ?? 'keep';
            if ($kind === 'evening' && $s['moves'][$id] === 'today') $s['moves'][$id] = 'keep';
        } elseif ($field === 'pick' && $id > 0) {
            if (in_array($id, $s['picks'], true)) {
                $s['picks'] = array_values(array_diff($s['picks'], [$id]));
            } elseif (count($s['picks']) < 3) {
                $s['picks'][] = $id;
            }
        }
        $this->putState($c, $s);
        $kind === 'morning' ? $this->morningScreen($c, $m) : $this->eveningScreen($c, $m);
    }

    private function ritualAskText(string|int $c, ?int $m, string $kind, string $what): void
    {
        $s = $this->ritualDraft($c, $kind, $m);
        $s['awaiting'] = $what;
        $s['mid'] = $m;
        $this->putState($c, $s);
        $this->show($c, $m, $this->g('rit.ask_'.$what), [$this->back($kind === 'morning' ? 'rm' : 're', $this->t('btn.cancel'))]);
    }

    /** Free text while a ritual waits for the intention / wins. */
    private function ritualText(string|int $c, array $s, string $text): void
    {
        if (!in_array($s['awaiting'] ?? null, ['intention', 'wins'], true)) {
            $this->show($c, null, $this->t('use_menu'), $this->mainKeyboard());
            return;
        }
        $s[$s['awaiting']] = mb_substr(trim(strip_tags($text)), 0, $s['awaiting'] === 'wins' ? 1000 : 500);
        $kind = $s['kind'];
        $s['awaiting'] = null;
        $this->putState($c, $s);
        $kind === 'morning' ? $this->morningScreen($c, null) : $this->eveningScreen($c, null);
    }

    private function ritualSave(string|int $c, ?int $m, string $kind): void
    {
        $s = $this->ritualDraft($c, $kind, $m);
        $moves = [];
        foreach ($s['moves'] as $id => $a) {
            if ($a !== 'keep') $moves[] = ['task_id' => (int) $id, 'action' => $a];
        }
        $this->guarded($c, $m, $kind === 'morning' ? 'rm' : 're', function () use ($c, $m, $kind, $s, $moves) {
            $svc = app(RitualService::class);
            if ($kind === 'morning') {
                $svc->saveMorning($this->user, ['energy' => $s['energy'], 'intention' => $s['intention'], 'top_task_ids' => $s['picks'], 'moves' => $moves]);
            } else {
                $svc->saveEvening($this->user, ['mood' => $s['mood'], 'wins' => $s['wins'], 'tomorrow_task_ids' => $s['picks'], 'moves' => $moves]);
            }
            $this->ritualsMenu($c, $m, '✅ '.__('rituals.'.$kind.'_saved'));
        });
    }

    private function ritualSkip(string|int $c, ?int $m, string $kind): void
    {
        if (!in_array($kind, ['morning', 'evening'], true)) {
            $this->ritualsMenu($c, $m);
            return;
        }
        app(RitualService::class)->skip($this->user, $kind);
        $this->ritualsMenu($c, $m, __('rituals.skipped'));
    }

    // ================================================================== focus

    private function focusScreen(string|int $c, ?int $m, ?string $notice = null): void
    {
        $this->clearState($c);
        $svc = app(FocusService::class);
        $s = $svc->current();
        $lines = $notice ? [$notice, ''] : [];
        $lines[] = $this->g('fc.title');
        $kb = [];
        if ($s) {
            $lines[] = '';
            $lines[] = ($s->status === 'running' ? '▶️ ' : '⏸ ').$this->g('fc.status_'.$s->status).($s->task ? ' — '.$s->task->title : '');
            $lines[] = $this->g('fc.remaining', ['time' => $this->mins((int) ceil($s->remaining_seconds / 60))]).' · '.$this->g('fc.elapsed', ['time' => $this->mins(intdiv($s->elapsed_seconds, 60))]);
            $kb[] = $s->status === 'running'
                ? [$this->btn('⏸ '.$this->g('fc.pause'), 'fcp'), $this->btn('🔄 '.$this->g('fc.refresh'), 'fc')]
                : [$this->btn('▶️ '.$this->g('fc.resume'), 'fcr'), $this->btn('🔄 '.$this->g('fc.refresh'), 'fc')];
            $kb[] = [$this->btn('✅ '.$this->g('fc.finish'), 'fcf'), $this->btn('✖️ '.$this->g('fc.cancel'), 'fcx')];
        } else {
            $def = (int) ($this->user->preference('focus_minutes') ?: 25);
            $stats = $svc->stats($this->user, 7);
            $lines[] = '';
            $lines[] = $this->g('fc.none');
            $lines[] = $this->g('fc.week', ['time' => $this->mins((int) ($stats['total_minutes'] ?? 0)), 'n' => $this->n((int) ($stats['sessions'] ?? 0))]);
            $kb[] = [$this->btn('⏱ '.$this->mins($def), 'fcs:0:'.$def), $this->btn('⏱ '.$this->mins(50), 'fcs:0:50')];
            $wn = app(\App\Services\Planner\TaskRanker::class)->whatNow($this->user, 4)['ranked'];
            if ($wn) $lines[] = $this->g('fc.on_task');
            foreach ($wn as $x) {
                $kb[] = [$this->btn('🎯 '.$this->short($x['title']), 'fcs:'.$x['id'].':0')];
            }
        }
        $kb[] = $this->back('home');
        $this->show($c, $m, implode("\n", $lines), $kb);
    }

    private function focusStart(string|int $c, ?int $m, int $taskId, int $minutes): void
    {
        if ($taskId > 0) {
            $t = $this->findOwned('task', $taskId);
            if (!$t || in_array($t->status, ['completed', 'cancelled'], true)) {
                $this->show($c, $m, $this->t('not_owned'), [$this->back('fc')]);
                return;
            }
        }
        $minutes = $minutes > 0 ? $minutes : (int) ($this->user->preference('focus_minutes') ?: 25);
        $svc = app(FocusService::class);
        $already = $svc->current();
        // A fixed first block marks sessions started from the bot (still a valid UUID), so the
        // end-of-timer message goes to Telegram and not to people using the web timer.
        $uuid = self::FOCUS_UUID_PREFIX.substr((string) \Illuminate\Support\Str::uuid(), 8);
        $svc->start($this->user, ['task_id' => $taskId ?: null, 'planned_minutes' => min(240, max(1, $minutes)), 'client_uuid' => $uuid]);
        $this->focusScreen($c, $m, $already ? '⚠️ '.$this->g('fc.already') : '▶️ '.$this->g('fc.started', ['time' => $this->mins($minutes)]));
    }

    private function focusAction(string|int $c, ?int $m, string $action): void
    {
        $svc = app(FocusService::class);
        $s = $svc->current();
        if (!$s) {
            $this->focusScreen($c, $m);
            return;
        }
        $notice = null;
        match ($action) {
            'pause' => $svc->pause($s),
            'resume' => $svc->resume($s),
            'cancel' => $svc->cancel($s),
            default => null,
        };
        if ($action === 'finish') {
            $done = $svc->finish($s);
            $min = intdiv((int) $done->actual_seconds, 60);
            $notice = '✅ '.($done->execution_log_id ? $this->g('fc.finished_logged', ['time' => $this->mins($min)]) : $this->g('fc.finished_short'));
        } elseif ($action === 'cancel') {
            $notice = $this->g('fc.cancelled');
        }
        $this->focusScreen($c, $m, $notice);
    }

    // ================================================================== smart lists & tags

    private function smartMenu(string|int $c, ?int $m): void
    {
        $this->clearState($c);
        $kb = [];
        $row = [];
        foreach (array_keys(TaskFilter::PRESETS) as $k) {
            $row[] = $this->btn(__('filters.p_'.$k), 'slv:'.$k.':0');
            if (count($row) === 2) { $kb[] = $row; $row = []; }
        }
        $kb[] = $row;
        $tags = $this->owned(Tag::class)->orderBy('name')->limit(12)->get(['id', 'name']);
        $row = [];
        foreach ($tags as $t) {
            $row[] = $this->btn('#'.$this->short($t->name, 20), 'slt:'.$t->id.':0');
            if (count($row) === 3) { $kb[] = $row; $row = []; }
        }
        $kb[] = $row;
        $kb[] = $this->back('tasks');
        $this->show($c, $m, $this->g('sl.title')."\n\n".($tags->isEmpty() ? __('filters.no_tags') : $this->g('sl.tags_hint')), $kb);
    }

    private function smartList(string|int $c, ?int $m, string $kind, string $key, int $page): void
    {
        $f = app(TaskFilter::class);
        if ($kind === 'tag') {
            $tag = $this->owned(Tag::class)->find((int) $key);
            if (!$tag) { $this->show($c, $m, $this->t('not_owned'), [$this->back('sl')]); return; }
            $def = ['tags' => [$tag->id]];
            $title = '#'.$tag->name;
            $cb = 'slt:'.$tag->id;
        } else {
            if (!isset(TaskFilter::PRESETS[$key])) { $this->smartMenu($c, $m); return; }
            $def = TaskFilter::PRESETS[$key];
            $title = __('filters.p_'.$key);
            $cb = 'slv:'.$key;
        }
        $q = $f->apply($this->owned(Task::class), $f->validate($def, $this->user), $this->user)
            ->orderByRaw('deadline is null')->orderBy('deadline')->orderBy('id');
        $per = 8;
        $total = (clone $q)->count();
        $rows = $q->skip($page * $per)->take($per)->get(['id', 'title', 'deadline', 'estimated_minutes', 'status']);
        $lines = ['🔎 '.$title.' — '.$this->n($total)];
        $kb = [];
        foreach ($rows as $t) {
            $kb[] = [$this->btn($this->short($t->title, 40).($t->deadline ? ' · '.$this->dt($t->deadline, 'm/d') : ''), 'v:task:'.$t->id)];
        }
        if ($total === 0) $lines[] = "\n".$this->g('sl.empty');
        $nav = [];
        if ($page > 0) $nav[] = $this->btn('‹', $cb.':'.($page - 1));
        if (($page + 1) * $per < $total) $nav[] = $this->btn('›', $cb.':'.($page + 1));
        $kb[] = $nav;
        $kb[] = $this->back('sl');
        $this->show($c, $m, implode("\n", $lines), $kb);
    }

    // ================================================================== breakdown & estimate

    private function breakdownScreen(string|int $c, ?int $m, int $id, ?string $notice = null): void
    {
        $this->clearState($c);
        $t = $this->findOwned('task', $id);
        if (!$t) { $this->show($c, $m, $this->t('not_owned'), [$this->back('tasks')]); return; }
        $kids = $t->children()->orderBy('id')->get(['id', 'title', 'status', 'estimated_minutes']);
        $lines = $notice ? [$notice, ''] : [];
        $lines[] = '🧩 '.__('breakdown.title').': '.$t->title;
        $lines[] = '';
        $lines[] = $kids->isEmpty() ? $this->g('bd.none') : __('breakdown.existing').':';
        foreach ($kids as $k) $lines[] = ($k->status === 'completed' ? '✅ ' : '▫️ ').$k->title.($k->estimated_minutes ? ' · '.$this->mins((int) $k->estimated_minutes) : '');
        $kb = [];
        $open = !in_array($t->status, ['completed', 'cancelled'], true);
        if ($open && app(TaskBreakdownService::class)->aiAvailable($this->user)) $kb[] = [$this->btn('🤖 '.__('breakdown.ask_ai'), 'bdai:'.$id)];
        if ($open) $kb[] = [$this->btn('➕ '.$this->g('bd.add'), 'bdadd:'.$id)];
        foreach ($kids->where('status', '!=', 'completed')->take(6) as $k) $kb[] = [$this->btn('▫️ '.$this->short($k->title), 'v:task:'.$k->id)];
        $kb[] = $this->back('v:task:'.$id);
        $this->show($c, $m, implode("\n", $lines), $kb);
    }

    private function breakdownPropose(string|int $c, ?int $m, int $id): void
    {
        $t = $this->findOwned('task', $id);
        if (!$t) { $this->show($c, $m, $this->t('not_owned'), [$this->back('tasks')]); return; }
        $svc = app(TaskBreakdownService::class);
        if (!$svc->aiAvailable($this->user)) { $this->breakdownScreen($c, $m, $id, '⚠️ '.__('breakdown.ai_unavailable')); return; }
        $this->show($c, $m, '⏳ '.__('breakdown.asking'));
        try {
            $r = $svc->propose($this->user, $t);
        } catch (ValidationException $e) {
            $this->breakdownScreen($c, $m, $id, '⚠️ '.collect($e->errors())->flatten()->first());
            return;
        } catch (\App\Exceptions\PlanLimitReached $e) {
            $this->breakdownScreen($c, $m, $id, '⚠️ '.$e->getMessage());
            return;
        } catch (\Throwable $e) {
            report($e);
            $this->breakdownScreen($c, $m, $id, '⚠️ '.__('breakdown.ai_failed'));
            return;
        }
        $steps = array_map(fn ($s) => ['title' => $s['title'], 'estimated_minutes' => $s['suggested_minutes'] ?? $s['estimated_minutes']], $r['steps']);
        $this->putState($c, ['mode' => 'bdprop', 'task' => $id, 'steps' => $steps, 'mid' => $m]);
        $lines = ['🤖 '.$t->title, '', __('breakdown.ai_note')];
        if ($r['calibrated'] && $r['factor']) $lines[] = __('breakdown.calibrated', ['factor' => $this->n($r['factor'])]);
        $lines[] = '';
        foreach ($steps as $i => $s) $lines[] = $this->n($i + 1).'. '.$s['title'].($s['estimated_minutes'] ? ' · '.$this->mins((int) $s['estimated_minutes']) : '');
        $this->show($c, $m, implode("\n", $lines), [
            [$this->btn('✅ '.__('breakdown.create'), 'bdok:'.$id)],
            [$this->btn('✖️ '.$this->t('btn.cancel'), 'bd:'.$id)],
        ]);
    }

    private function breakdownConfirm(string|int $c, ?int $m, int $id): void
    {
        $s = $this->state($c);
        $t = $this->findOwned('task', $id);
        if (!$t || ($s['mode'] ?? null) !== 'bdprop' || (int) ($s['task'] ?? 0) !== $id) {
            $this->breakdownScreen($c, $m, $id, $t ? '⚠️ '.$this->g('bd.expired') : null);
            return;
        }
        $this->guarded($c, $m, 'bd:'.$id, function () use ($c, $m, $id, $t, $s) {
            $made = app(TaskBreakdownService::class)->create($this->user, $t, ['steps' => $s['steps']]);
            $this->breakdownScreen($c, $m, $id, '✅ '.__('breakdown.created', ['count' => $this->n(count($made))]));
        });
    }

    private function breakdownAsk(string|int $c, ?int $m, int $id): void
    {
        $t = $this->findOwned('task', $id);
        if (!$t) { $this->show($c, $m, $this->t('not_owned'), [$this->back('tasks')]); return; }
        $this->putState($c, ['mode' => 'bdadd', 'task' => $id, 'mid' => $m]);
        $this->show($c, $m, $this->g('bd.add_prompt', ['title' => $t->title]), [$this->back('bd:'.$id, $this->t('btn.cancel'))]);
    }

    /** One step per line; an optional number at the end is minutes ("Write copy - 30"). */
    private function breakdownText(string|int $c, array $s, string $text): void
    {
        $id = (int) ($s['task'] ?? 0);
        $t = $this->findOwned('task', $id);
        if (!$t) { $this->show($c, null, $this->t('not_owned'), $this->mainKeyboard()); return; }
        $steps = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim(LocalDate::latinDigits(trim($line)), " \t-•*");
            if ($line === '') continue;
            $min = null;
            if (preg_match('/^(.*?)[\s\-–—|:،,]+(\d{1,4})\s*(?:min|m|دقیقه|دق)?$/u', $line, $mm) && trim($mm[1]) !== '') {
                $line = trim($mm[1]);
                $min = (int) $mm[2];
            }
            $steps[] = ['title' => mb_substr($line, 0, 255), 'estimated_minutes' => $min !== null ? min(1440, $min) : null];
        }
        $this->clearState($c);
        $this->guarded($c, null, 'bd:'.$id, function () use ($c, $id, $t, $steps) {
            $made = app(TaskBreakdownService::class)->create($this->user, $t, ['steps' => array_slice($steps, 0, TaskBreakdownService::MAX_CREATE)]);
            $this->breakdownScreen($c, null, $id, '✅ '.__('breakdown.created', ['count' => $this->n(count($made))]));
        });
    }

    private function estimateScreen(string|int $c, ?int $m, int $id): void
    {
        $t = $this->findOwned('task', $id);
        if (!$t) { $this->show($c, $m, $this->t('not_owned'), [$this->back('tasks')]); return; }
        $r = app(EstimateService::class)->suggest($this->user, ['title' => $t->title, 'project_id' => $t->project_id, 'exclude_id' => $t->id,
            'tag_ids' => $t->tags()->pluck('tags.id')->all(), 'estimated_minutes' => (int) $t->estimated_minutes]);
        $lines = ['⏳ '.$t->title, $this->g('est.current', ['time' => $t->estimated_minutes ? $this->mins((int) $t->estimated_minutes) : '—']), ''];
        $kb = [];
        if ($r['suggested_minutes']) {
            $lines[] = $r['basis'] === 'similar'
                ? __('breakdown.est_similar', ['time' => $this->mins($r['suggested_minutes']), 'count' => $this->n($r['similar_count']), 'min' => $this->mins($r['range'][0]), 'max' => $this->mins($r['range'][1])])
                : __('breakdown.est_factor', ['time' => $this->mins($r['suggested_minutes'])]);
            foreach ($r['examples'] as $x) $lines[] = '  • '.$x['title'].' — '.$this->mins($x['actual_minutes']);
            if ((int) $t->estimated_minutes !== (int) $r['suggested_minutes'] && !in_array($t->status, ['completed', 'cancelled'], true)) {
                $kb[] = [$this->btn('✅ '.__('breakdown.est_use', ['time' => $this->mins($r['suggested_minutes'])]), 'estok:'.$id.':'.$r['suggested_minutes'])];
            }
        } else {
            $lines[] = __('breakdown.'.(($r['reason'] ?? '') === 'estimate_matches_history' ? 'est_ok' : 'est_none'));
        }
        $kb[] = $this->back('v:task:'.$id);
        $this->show($c, $m, implode("\n", $lines), $kb);
    }

    private function estimateApply(string|int $c, ?int $m, int $id, int $minutes): void
    {
        $t = $this->findOwned('task', $id);
        if (!$t || $minutes < 5 || $minutes > 1440 || in_array($t->status, ['completed', 'cancelled'], true)) {
            $this->show($c, $m, $this->t('not_owned'), [$this->back('tasks')]);
            return;
        }
        $before = (int) $t->estimated_minutes;
        $t->update(['estimated_minutes' => $minutes]);
        app(\App\Services\Planner\ActivityLogger::class)->log('updated', Task::class, $t->id, ['estimated_minutes' => $before], ['estimated_minutes' => $minutes, 'source' => 'estimate_suggestion']);
        $this->entityView($c, $m, 'task', $id, '✅ '.$this->g('est.applied', ['time' => $this->mins($minutes)]));
    }

    /** Extra lines in the task card: tags, parent, subtasks. */
    private function taskExtras(Task $x): array
    {
        $out = [];
        $tags = $x->tags()->orderBy('name')->pluck('name')->all();
        if ($tags) $out[] = '🏷 '.implode(' ', array_map(fn ($n) => '#'.$n, $tags));
        if ($x->parent_task_id && ($p = $this->findOwned('task', (int) $x->parent_task_id))) $out[] = '↳ '.__('breakdown.subtask_of', ['title' => $p->title]);
        $total = $x->children()->count();
        if ($total) $out[] = '🧩 '.__('breakdown.subtasks_count', ['done' => $this->n($x->children()->where('status', 'completed')->count()), 'total' => $this->n($total)]);
        return $out;
    }

    /** Extra buttons on an open task's card. */
    private function taskExtraButtons(int $id): array
    {
        return [$this->btn('⏱ '.$this->g('btn.focus'), 'fcs:'.$id.':0'), $this->btn('🧩 '.$this->g('btn.steps'), 'bd:'.$id), $this->btn('⏳ '.$this->g('btn.estimate'), 'est:'.$id)];
    }

    /** Routes for this trait; returns false when the action is not one of ours. */
    private function routeGrowth(string|int $c, int $m, string $a, array $p): bool
    {
        $i1 = $this->int($p[1] ?? null);
        $i2 = $this->int($p[2] ?? null);
        switch ($a) {
            case 'rit': $this->ritualsMenu($c, $m); return true;
            case 'rm': $this->clearState($c); $this->morningScreen($c, $m); return true;
            case 're': $this->clearState($c); $this->eveningScreen($c, $m); return true;
            case 'rme': $this->ritualUpdate($c, $m, 'morning', 'energy', $i1); return true;
            case 'rem': $this->ritualUpdate($c, $m, 'evening', 'mood', $i1); return true;
            case 'rmm': $this->ritualUpdate($c, $m, 'morning', 'move', $i1, (string) ($p[2] ?? 'k')); return true;
            case 'rmv': $this->ritualUpdate($c, $m, 'evening', 'move', $i1, (string) ($p[2] ?? 'k')); return true;
            case 'rmp': $this->ritualUpdate($c, $m, 'morning', 'pick', $i1); return true;
            case 'rep': $this->ritualUpdate($c, $m, 'evening', 'pick', $i1); return true;
            case 'rmi': $this->ritualAskText($c, $m, 'morning', 'intention'); return true;
            case 'rew': $this->ritualAskText($c, $m, 'evening', 'wins'); return true;
            case 'rms': $this->ritualSave($c, $m, 'morning'); return true;
            case 'res': $this->ritualSave($c, $m, 'evening'); return true;
            case 'rsk': $this->ritualSkip($c, $m, (string) ($p[1] ?? '')); return true;
            case 'fc': $this->focusScreen($c, $m); return true;
            case 'fcs': $this->focusStart($c, $m, $i1, $i2); return true;
            case 'fcp': $this->focusAction($c, $m, 'pause'); return true;
            case 'fcr': $this->focusAction($c, $m, 'resume'); return true;
            case 'fcf': $this->focusAction($c, $m, 'finish'); return true;
            case 'fcx': $this->focusAction($c, $m, 'cancel'); return true;
            case 'sl': $this->smartMenu($c, $m); return true;
            case 'slv': $this->smartList($c, $m, 'preset', (string) ($p[1] ?? ''), $i2); return true;
            case 'slt': $this->smartList($c, $m, 'tag', (string) $i1, $i2); return true;
            case 'bd': $this->breakdownScreen($c, $m, $i1); return true;
            case 'bdai': $this->breakdownPropose($c, $m, $i1); return true;
            case 'bdok': $this->breakdownConfirm($c, $m, $i1); return true;
            case 'bdadd': $this->breakdownAsk($c, $m, $i1); return true;
            case 'est': $this->estimateScreen($c, $m, $i1); return true;
            case 'estok': $this->estimateApply($c, $m, $i1, $i2); return true;
        }
        return false;
    }
}
