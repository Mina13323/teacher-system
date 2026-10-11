/**
 * Groups a page of a student's attempts (ordered newest exam first, then
 * attempt number) into consecutive exam groups for the student page. The page
 * is server-paginated, so an exam may continue on the next page.
 */
export function groupAttemptsByExam(rows) {
    const groups = [];
    for (const row of Array.isArray(rows) ? rows : []) {
        const last = groups[groups.length - 1];
        if (last && last.exam_id === row.exam_id) {
            last.attempts.push(row);
        } else {
            groups.push({ exam_id: row.exam_id, exam_title: row.exam_title, attempts: [row] });
        }
    }
    return groups;
}

/** Score text for staff screens: a real 0 stays "0", no score yet is "—". */
export function scoreText(score, percentage) {
    if (score === null || score === undefined) return '—';
    return percentage === null || percentage === undefined ? String(score) : `${score} (${percentage}%)`;
}
