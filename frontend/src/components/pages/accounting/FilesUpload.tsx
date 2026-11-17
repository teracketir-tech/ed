import { ConfigProvider, Upload, type UploadFile } from "antd";
import { Plus } from "lucide-react";
import { toast } from "sonner";
import { MAX_FILES, MAX_FILE_SIZE, UPLOAD_EXTENSIONS } from "./uploadHelpers";

interface FilesUploadProps {
    fileList: UploadFile[];
    setFileList: (files: UploadFile[]) => void;
}

/**
 * Dash-style card uploader for accounting attachments: nothing leaves the
 * browser here — the form sends the files together with its own submit.
 */
export default function FilesUpload({ fileList, setFileList }: FilesUploadProps) {
    const beforeUpload = (file: File) => {
        const extension = file.name.split(".").pop()?.toLowerCase() || "";

        if (!UPLOAD_EXTENSIONS.includes(extension)) {
            toast.error(`پسوند فایل «${file.name}» مجاز نیست`);
            return Upload.LIST_IGNORE;
        }

        if (file.size > MAX_FILE_SIZE) {
            toast.error(`حجم فایل «${file.name}» بیش از ۱۰ مگابایت است`);
            return Upload.LIST_IGNORE;
        }

        return false;
    };

    return (
        <ConfigProvider direction="rtl">
            <div className="w-full">
                <label className="block mb-2">آپلود فایل</label>
                <Upload
                    multiple
                    maxCount={MAX_FILES}
                    listType="picture-card"
                    fileList={fileList}
                    beforeUpload={beforeUpload}
                    onChange={({ fileList: next }) => setFileList(next)}
                    showUploadList={{ showPreviewIcon: false }}
                >
                    {fileList.length >= MAX_FILES ? null : (
                        <button type="button" className="flex items-center gap-1">
                            <Plus size={18} />
                            <span className="text-xs">آپلود فایل</span>
                        </button>
                    )}
                </Upload>
            </div>
        </ConfigProvider>
    );
}
