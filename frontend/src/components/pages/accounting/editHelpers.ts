import type { UploadFile } from "antd";
import { parseFileUrls, storedFileList } from "./uploadHelpers";

/** The stored value of a row's detail, by the type that account books. */
function storedDetail(row: any, detailName: string): string {
    switch (detailName) {
        case "contact_id":
            return row?.contact_id ? String(row.contact_id) : "";

        case "bank_id":
            return row?.bank_id ? String(row.bank_id) : "";

        case "product_id":
            return row?.product_id ? String(row.product_id) : "";

        case "material_id":
            return row?.material_id ? String(row.material_id) : "";

        default:
            return "";
    }
}

/**
 * A stored row the way the form shapes one.
 *
 * A sub-account row was booked against its parent account with the child
 * kept in `sub_account_id`; the form selects the child and posts
 * detail_id = 1, which is exactly what books it back the same way.
 */
export function documentRow(row: any) {
    const detailName = row?.sub_account_id ? row?.subAccount?.detail_name || "account_id" : row?.accountingData?.detail_name || "";

    return {
        id: row.id,
        accounting_data_id: row?.sub_account_id || row?.accounting_data_id,
        detail_name: detailName,
        detail_id: row?.sub_account_id ? 1 : storedDetail(row, detailName),
        details: [],
        description: row?.description || "",
        debit: row?.debit ?? "",
        credit: row?.credit ?? "",
    };
}

/** The document fields the edit form owns. */
export function documentValues(document: any, fallbackCompanyId: any) {
    return {
        company_id: document?.company_id || fallbackCompanyId || "",
        date: document?.date || "",
        project_id: document?.project_id ? String(document.project_id) : "",
        status: document?.status === "approved" ? "2" : "1",
        description: document?.description || "",
        pay_accounting_item_id: document?.pay_accounting_item_id ? String(document.pay_accounting_item_id) : "",
    };
}

/** The registration files the document already holds, as upload entries. */
export function documentFileList(document: any): UploadFile[] {
    return storedFileList(parseFileUrls(document?.image));
}
