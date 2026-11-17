import { type UploadFile } from "antd";

export const MAX_FILES = 10;
export const MAX_FILE_SIZE = 10 * 1024 * 1024;
export const UPLOAD_EXTENSIONS = ["pdf", "png", "jpg", "jpeg", "gif", "webp", "xls", "xlsx", "zip"];

/** Appends any value tree to FormData under PHP bracket keys (`items[0][debit]`). */
export function appendFormValue(formData: FormData, key: string, value: unknown) {
    if (value === null || value === undefined || value === "") return;

    if (Array.isArray(value)) {
        value.forEach((entry, i) => appendFormValue(formData, `${key}[${i}]`, entry));
        return;
    }

    if (typeof value === "object") {
        Object.entries(value as Record<string, unknown>).forEach(([childKey, entry]) =>
            appendFormValue(formData, `${key}[${childKey}]`, entry),
        );
        return;
    }

    formData.append(key, String(value));
}

/** The raw File objects behind an antd fileList (upload happens on submit). */
export function collectFiles(fileList: UploadFile[]): File[] {
    return fileList.map((file) => (file as { originFileObj?: File }).originFileObj).filter((file): file is File => Boolean(file));
}

/** A file column holds a JSON array of urls; older rows hold one bare url. */
export function parseFileUrls(value?: string | null): string[] {
    if (!value) return [];

    try {
        const parsed: unknown = JSON.parse(value);

        return Array.isArray(parsed) ? (parsed as unknown[]).filter((url): url is string => typeof url === "string") : [String(parsed)];
    } catch {
        return [value];
    }
}

/** The stored urls as upload entries, so the edit form can show and drop them. */
export function storedFileList(urls: string[]): UploadFile[] {
    return urls.map((url, index) => ({
        uid: `stored-${index}-${url}`,
        name: decodeURIComponent(url.split("/").pop() || "file"),
        status: "done",
        url,
    }));
}

/**
 * The stored urls the form still shows. A newly picked file carries an
 * `originFileObj` and travels as a real upload; anything left without one is
 * a url the document already held, which the backend must keep.
 */
export function keptImageUrls(fileList: UploadFile[]): string[] {
    return fileList
        .filter((file) => !(file as { originFileObj?: File }).originFileObj)
        .map((file) => file.url)
        .filter((url): url is string => Boolean(url));
}
