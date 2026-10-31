/** Select expanded session IDs from precomputed time statuses and workflow steps. */
export function expandedShiftIds(shifts) {
    const active = shifts.filter((shift) => shift.status.tone !== 'muted');
    if (active.length) return active.map((shift) => shift.session_id);

    const unfinished = shifts.find((shift) => shift.steps.some((step) => !step.done));
    return unfinished ? [unfinished.session_id] : [];
}
