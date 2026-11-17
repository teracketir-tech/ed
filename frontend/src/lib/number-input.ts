// Helpers behind the shared numeric Input (components/shared/inputs).
//
// Two representations of the same field:
//   * the stored value - English digits, at most one ".", one leading "-".
//     This is what formik holds and what the API receives.
//   * the shown value  - the stored value grouped with "," every three digits.
//
// Normalising on every keystroke is what keeps Persian digits (۱۸), Persian
// separators (٬ ٫) and stray characters from ever reaching the API, and the
// caret math is what lets the grouping exist at all: the shown text is longer
// than the stored value, so the cursor has to be moved to its new place.
// Grouping only ever changes the shown text, never the value itself, so a
// leading zero (a code, a phone number) survives untouched.

const GROUP = 3;
const PERSIAN_ZERO = 0x06f0; // ۰
const ARABIC_ZERO = 0x0660; // ٠
const PERSIAN_DECIMAL_SEPARATOR = "\u066b"; // ٫

/** Convert Persian (۰-۹) and Arabic-Indic (٠-٩) digits to their English form. */
export const toEnglishDigits = (value: string): string =>
    value.replace(/[\u06f0-\u06f9\u0660-\u0669]/g, (digit) => {
        const code = digit.charCodeAt(0);

        return String(code - (code >= PERSIAN_ZERO ? PERSIAN_ZERO : ARABIC_ZERO));
    });

/** Reduce anything to the canonical value: digits, one ".", one leading "-". */
export const normalizeNumberInput = (raw: unknown): string => {
    const source = raw === null || raw === undefined ? "" : String(raw);
    const value = toEnglishDigits(source).replaceAll(PERSIAN_DECIMAL_SEPARATOR, ".");
    const negative = value.trimStart().startsWith("-");

    // drops ",", "٬", spaces, letters and every "." but the first one
    const digits = value.replace(/[^\d.]/g, "");
    const dot = digits.indexOf(".");
    const single = dot === -1 ? digits : digits.slice(0, dot + 1) + digits.slice(dot + 1).replaceAll(".", "");

    return negative ? `-${single}` : single;
};

export interface FormattedNumberInput {
    text: string;
    caret: number;
}

/** "1234567" -> "1,234,567"; a code like "09127107619" is left as it is. */
const groupInteger = (integer: string): string => {
    if (integer.length > GROUP && integer.startsWith("0")) return integer;

    return integer.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
};

/** Separators the grouping puts in front of the first `until` digits of a `length` digits part. */
const separatorsBefore = (length: number, until: number): number => {
    let count = 0;

    for (let i = 1; i < length && i < until; i++) {
        if ((length - i) % GROUP === 0) count++;
    }

    return count;
};

/**
 * Group `value` for display and move `caret` (an index inside `value`) to the
 * matching index inside the grouped text. Without a caret the text alone comes
 * back, already positioned at its end.
 */
export const formatNumberInput = (value: string, caret?: number): FormattedNumberInput => {
    const negative = value.startsWith("-");
    const body = negative ? value.slice(1) : value;
    const dot = body.indexOf(".");
    const integer = dot === -1 ? body : body.slice(0, dot);
    const fraction = dot === -1 ? "" : body.slice(dot);
    const sign = negative ? 1 : 0;
    const grouped = groupInteger(integer);
    const text = `${negative ? "-" : ""}${grouped}${fraction}`;

    if (caret === undefined) return { text, caret: text.length };

    const offset = caret - sign; // index inside body
    const within = Math.min(offset, integer.length);
    // an ungrouped value (a code like "09127107619") never moves: same indexes
    const separators = grouped === integer ? 0 : separatorsBefore(integer.length, within);

    return { text, caret: sign + within + separators + Math.max(0, offset - integer.length) };
};
