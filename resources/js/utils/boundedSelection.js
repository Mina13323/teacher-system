/**
 * Toggle one numeric record id in a bounded selection.
 *
 * Membership is checked before availability/limit checks so an already-selected
 * record can always be removed, even if it has since become unavailable.
 */
export function toggleBoundedSelection(currentIds, rawId, limit, unavailable = false) {
    const id = Number(rawId);
    if (!Number.isSafeInteger(id) || id < 1) {
        return { ids: currentIds, action: 'invalid' };
    }

    if (currentIds.includes(id)) {
        return {
            ids: currentIds.filter((selected) => selected !== id),
            action: 'removed',
        };
    }

    if (unavailable) {
        return { ids: currentIds, action: 'unavailable' };
    }

    if (currentIds.length >= limit) {
        return { ids: currentIds, action: 'limit' };
    }

    return { ids: [...currentIds, id], action: 'added' };
}
