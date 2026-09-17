import i18n from '@/i18n';

// Shared academic-year / subject labels.
//
// These were local functions in Teacher/Students.vue, duplicated by anything
// else that needed to render the same two fields. They resolve through the
// i18n instance at call time rather than capturing `t` at import time, so a
// language switch re-renders correctly.

const t = (key) => i18n.global.t(key);

/** Human label for a student's academic subject. */
export function getSubjectLabel(student) {
    if (!student) return '';
    if (student.academic_subject_label) return student.academic_subject_label;
    if (student.academic_subject === 'history') return t('subject.history');
    if (student.academic_subject === 'geography') return t('subject.geography');
    if (student.academic_subject === 'both') return t('subject.both');

    return t('subject.general');
}

/** Human label for a student's academic year. */
export function getYearLabel(student) {
    if (!student) return '';
    if (student.academic_year_label) return student.academic_year_label;
    if (student.academic_year === 'secondary_1') return t('students.secondary1');
    if (student.academic_year === 'secondary_2') return t('students.secondary2');
    if (student.academic_year === 'secondary_3') return t('students.secondary3');

    return student.academic_year || t('students.yearUnspecified');
}
