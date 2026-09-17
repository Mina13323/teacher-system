<script setup>
import { getSubjectLabel, getYearLabel } from '@/utils/studentLabels';

// Printable credential sheet.
//
// Extracted from Teacher/Students.vue, which had grown to hold the student
// table, the credential reveal, the WhatsApp modal, the notify modal and this
// sheet in one file. Owning the print styles here means the sheet's layout
// travels with its markup, and the preview on screen matches what prints.
defineProps({
    students: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="printable-area">
        <div v-for="st in students" :key="st.id" class="print-card">
            <div class="print-card__head">
                <span class="print-card__brand">{{ $t('students.printBrand') }}</span>
                <span class="print-card__code" dir="ltr">{{ st.student_code }}</span>
            </div>

            <div class="print-card__name" dir="rtl">{{ st.name }}</div>

            <div class="print-card__grid">
                <div class="print-card__row">
                    <span class="print-card__label">{{ $t('students.printCodeLabel') }}</span>
                    <span class="print-card__value print-card__value--mono" dir="ltr">{{ st.student_code }}</span>
                </div>
                <div class="print-card__row">
                    <span class="print-card__label">{{ $t('students.printEmailLabel') }}</span>
                    <span class="print-card__value print-card__value--mono" dir="ltr">{{ st.email }}</span>
                </div>
                <div class="print-card__row">
                    <span class="print-card__label">{{ $t('students.printPasswordLabel') }}</span>
                    <span class="print-card__value print-card__value--mono" dir="ltr">{{ st.revealed_password || $t('students.printPasswordUnavailable') }}</span>
                </div>
                <div class="print-card__row">
                    <span class="print-card__label">{{ $t('students.printYearLabel') }}</span>
                    <span class="print-card__value">{{ getYearLabel(st) }}</span>
                </div>
                <div v-if="st.academic_year === 'secondary_3'" class="print-card__row">
                    <span class="print-card__label">{{ $t('students.printSubjectLabel') }}</span>
                    <span class="print-card__value">{{ getSubjectLabel(st) }}</span>
                </div>
                <div v-if="st.phone" class="print-card__row">
                    <span class="print-card__label">{{ $t('students.printPhoneLabel') }}</span>
                    <span class="print-card__value" dir="ltr">{{ st.phone }}</span>
                </div>
            </div>

            <div class="print-card__foot">https://maherelmasry.com</div>
        </div>
    </div>
</template>

<style>
/* Compact credential cards so several fit on one printed page. The styles apply
   on screen too, so the preview matches what actually prints. */
.printable-area { display: flex; flex-direction: column; gap: 12px; padding: 8px; }

.print-card {
    border: 1.5px solid #1b2635;
    border-radius: 8px;
    padding: 10px 12px;
    background: #fff;
    font-size: 11px;
    line-height: 1.35;
    break-inside: avoid;
    page-break-inside: avoid;
}
.print-card__head { display: flex; justify-content: space-between; align-items: baseline; border-bottom: 1.5px solid #1b2635; padding-bottom: 4px; margin-bottom: 6px; }
.print-card__brand { font-weight: 800; font-size: 12px; letter-spacing: .02em; }
.print-card__code { font-family: monospace; font-weight: 700; color: #c1461f; }
.print-card__name { text-align: center; font-weight: 700; font-size: 12px; margin-bottom: 6px; }
.print-card__grid { display: flex; flex-direction: column; gap: 2px; }
.print-card__row { display: flex; justify-content: space-between; gap: 8px; }
.print-card__label { color: #5c7496; }
.print-card__value { font-weight: 600; text-align: right; }
.print-card__value--mono { font-family: monospace; }
.print-card__foot { text-align: center; margin-top: 6px; padding-top: 4px; border-top: 1px solid #c6cfdc; color: #7f93ad; font-size: 9px; }

@media print {
    body * { visibility: hidden; }
    .printable-area, .printable-area * { visibility: visible; }
    .printable-area { position: absolute; left: 0; top: 0; width: 100%; padding: 0; gap: 8px; }
    .no-print { display: none !important; }
    /* Let cards flow onto pages; only avoid splitting a single card mid-way. */
    .print-card { page-break-inside: avoid; }
}
</style>
