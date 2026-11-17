import { cn } from "@/lib/utils";
import { formatNumberInput, normalizeNumberInput } from "@/lib/number-input";
import { EyeIcon, EyeOffIcon } from "lucide-react";
import { useLayoutEffect, useRef, useState, type ReactNode } from "react";
import { getIn } from "formik";

interface Props {
    title?: any;
    placeholder?: string;
    name: string;
    inputMode?: "numeric" | "text";
    ltrInput?: boolean;
    autoFocus?: boolean;
    type?: "number" | "text" | "password" | "file" | "time";
    formik?: any;
    noErrorText?: boolean;
    required?: boolean;
    disabled?: boolean;
    isLoading?: boolean;
    autoComplete?: string;
    value?: any;
    onChange?: any;
    onBlur?: any;
}

// the same label is needed by all three input flavours below
const FieldLabel = ({ name, title, required, disabled }: { name: string; title?: ReactNode; required?: boolean; disabled?: boolean }) =>
    title ? (
        <label htmlFor={name} className={cn("flex items-center gap-0.5 text-[0.8rem]! text-[#000000e0] font-bold", disabled ? "opacity-30" : "opacity-100")}>
            {required ? <span className="text-red-500">* </span> : null}
            {title}
        </label>
    ) : null;

const Input = ({
    title,
    inputMode = "text",
    name,
    placeholder = "",
    ltrInput = false,
    autoFocus = false,
    type = "text",
    formik,
    noErrorText = false,
    required = true,
    disabled = false,
    isLoading = false,
    autoComplete = "true",
    value = "",
    onChange = () => {},
    onBlur = () => {},
}: Props) => {
    const activeValue = formik ? getIn(formik.values, name) : value || "";
    const error = formik ? getIn(formik.errors, name) : undefined;
    const touched = formik ? getIn(formik.touched, name) : undefined;

    const classes = `${
        error && touched ? "border-[#ff4d4f]" : "border-secondary focus:border-primary"
    } border focus:shadow rounded px-2 py-1.5 outline-none w-full h-[2.35rem]! bg-white transition-all disabled:opacity-50 placeholder:text-right text-[0.8rem] ${
        type == "password" ? "pl-11" : ""
    }`;

    const [showPass, setShowPass] = useState(false);

    const inputRef = useRef<HTMLInputElement>(null);
    const pendingCaret = useRef<number | null>(null);

    // the grouped text is longer than the stored value, so every keystroke
    // leaves the cursor where it no longer belongs - it is put back here
    useLayoutEffect(() => {
        if (pendingCaret.current === null) return;

        const position = Math.min(pendingCaret.current, inputRef.current?.value.length ?? 0);

        inputRef.current?.setSelectionRange(position, position);
        pendingCaret.current = null;
    });

    const onChangeNumber = (next: string) => {
        onChange && onChange(next);
        formik?.setFieldValue(name, next);
    };

    return (
        <>
            {type === "number" ? (
                <div className={`w-full flex flex-col gap-2 ${isLoading ? "animate-pulse" : ""}`}>
                    <FieldLabel name={name} title={title} required={required} disabled={disabled} />
                    <div className="w-full relative">
                        <input
                            ref={inputRef}
                            type="text"
                            inputMode="decimal"
                            className={classes}
                            id={name}
                            autoFocus={autoFocus}
                            dir="ltr"
                            placeholder={placeholder}
                            disabled={disabled}
                            autoComplete={autoComplete === "true" ? "off" : autoComplete}
                            value={formatNumberInput(normalizeNumberInput(activeValue)).text}
                            onChange={(e) => {
                                const raw = e.target.value;
                                const caret = e.target.selectionStart ?? raw.length;
                                const next = normalizeNumberInput(raw);

                                pendingCaret.current = formatNumberInput(next, normalizeNumberInput(raw.slice(0, caret)).length).caret;

                                onChangeNumber(next);
                            }}
                            onBlur={(e) => {
                                // "18." and "-" are half typed characters, not values
                                const clean = normalizeNumberInput(e.target.value);
                                const next = clean.endsWith(".") || clean === "-" ? clean.slice(0, -1) : clean;

                                if (next !== clean) onChangeNumber(next);

                                formik?.setFieldTouched(name, true);
                                onBlur && onBlur(e);
                            }}
                        />
                    </div>
                    {!noErrorText && error && touched && <p className="text-red-500 text-xs">{error}</p>}
                </div>
            ) : type === "file" ? (
                <div className={`w-full flex flex-col gap-2 ${isLoading ? "animate-pulse" : ""}`}>
                    <FieldLabel name={name} title={title} required={required} disabled={disabled} />
                    <div className="w-full relative">
                        <input
                            name={name}
                            className={classes}
                            id={name}
                            type={type}
                            autoFocus={autoFocus}
                            inputMode={inputMode}
                            dir={ltrInput ? "ltr" : "rtl"}
                            placeholder={placeholder}
                            disabled={disabled}
                            autoComplete={autoComplete}
                            onChange={(e) => formik?.setFieldValue(name, e.target?.files?.[0] || null)}
                        />
                    </div>

                    {!noErrorText && error && touched && <p className="text-red-500 text-xs">{error}</p>}
                </div>
            ) : (
                <div className={`w-full flex flex-col gap-2 ${isLoading ? "animate-pulse" : ""}`}>
                    <FieldLabel name={name} title={title} required={required} disabled={disabled} />
                    <div className="w-full relative">
                        <input
                            {...formik?.getFieldProps(name)}
                            value={activeValue}
                            onChange={(e: any) => {
                                onChange && onChange(e.target.value);
                                formik?.setFieldValue(name, e.target.value);
                            }}
                            className={classes}
                            id={name}
                            type={showPass ? "text" : type}
                            autoFocus={autoFocus}
                            inputMode={inputMode}
                            dir={ltrInput ? "ltr" : "rtl"}
                            placeholder={placeholder}
                            disabled={disabled}
                            autoComplete={autoComplete}
                        />

                        {type == "password" && (
                            <button type="button" onClick={() => setShowPass(!showPass)}>
                                {showPass ? (
                                    <EyeOffIcon className="size-5 absolute left-3 top-1/2 -translate-y-1/2 cursor-pointer" />
                                ) : (
                                    <EyeIcon className="size-5 absolute left-3 top-1/2 -translate-y-1/2 cursor-pointer" />
                                )}
                            </button>
                        )}
                    </div>

                    {!noErrorText && error && touched && <p className="text-red-500 text-xs">{error}</p>}
                </div>
            )}
        </>
    );
};

export default Input;
