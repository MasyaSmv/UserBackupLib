<?php

declare(strict_types=1);

namespace App\Plan;

use App\Plan\Exceptions\PlanCycleException;

/**
 * Упорядочивает правила так, чтобы дочерние строки удалялись раньше родительских.
 *
 * Дочерние строки отбираются подзапросом к родителю: если родителя удалить первым,
 * подзапрос перестанет находить его строку и дети останутся в базе навсегда. Именно так
 * и накопились существующие сироты.
 */
final class DeletionOrder
{
    /**
     * Топологическая сортировка: правило идёт раньше своих родителей, корень скоупа — после
     * всех остальных правил.
     *
     * Корень, у которого есть родитель в плане, даёт цикл: родитель ждёт корень как своего
     * потомка, а корень ждёт всех. Такой план описан неверно и отклоняется.
     *
     * @param array<string, UserDataRule> $rules Ключ — TableRef::key().
     *
     * @return array<int, UserDataRule>
     *
     * @throws PlanCycleException
     */
    public function sort(array $rules): array
    {
        $dependents = $this->buildDependents($rules);
        $pending = $rules;
        $ordered = [];

        while ($pending !== []) {
            $ready = $this->readyKeys($pending, $dependents);

            if ($ready === []) {
                throw new PlanCycleException(array_keys($pending));
            }

            foreach ($ready as $key) {
                $ordered[] = $pending[$key];
                unset($pending[$key]);
            }
        }

        return $ordered;
    }

    /**
     * Для каждой таблицы — множество правил, которые обязаны отработать раньше неё.
     *
     * @param array<string, UserDataRule> $rules
     *
     * @return array<string, array<int, string>>
     */
    private function buildDependents(array $rules): array
    {
        $dependents = [];

        foreach ($rules as $key => $rule) {
            foreach ($rule->parents() as $parentKey => $parent) {
                if (!isset($rules[$parentKey]) || $parentKey === $key) {
                    continue;
                }

                $dependents[$parentKey][] = $key;
            }
        }

        foreach ($this->scopeRootKeys($rules) as $rootKey) {
            foreach ($rules as $key => $rule) {
                if (!$rule->isScopeRoot()) {
                    $dependents[$rootKey][] = $key;
                }
            }
        }

        return $dependents;
    }

    /**
     * @param array<string, UserDataRule> $rules
     *
     * @return array<int, string>
     */
    private function scopeRootKeys(array $rules): array
    {
        return array_keys(array_filter(
            $rules,
            static fn (UserDataRule $rule): bool => $rule->isScopeRoot(),
        ));
    }

    /**
     * Таблицы, у которых не осталось необработанных зависимых правил.
     *
     * @param array<string, UserDataRule>      $pending
     * @param array<string, array<int, string>> $dependents
     *
     * @return array<int, string>
     */
    private function readyKeys(array $pending, array $dependents): array
    {
        $ready = [];

        foreach ($pending as $key => $rule) {
            $blockers = $dependents[$key] ?? [];

            foreach ($blockers as $blocker) {
                if (isset($pending[$blocker])) {
                    continue 2;
                }
            }

            $ready[] = $key;
        }

        return $ready;
    }
}
