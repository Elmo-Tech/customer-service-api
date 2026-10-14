# صلاحيات الفرونت: الشركات والعقود

هذا الملف يطابق الـ middleware الموجودة في الكود لموديول الشركات والعقود.

الرابط الأساسي: `https://customerservicebe.testingelmo.com/api/v1/admin`

كل الطلبات تحتاج توكن مستخدم الأدمن والصلاحية المذكورة أمام الراوت:

```http
Authorization: Bearer ADMIN_ACCESS_TOKEN
Accept: application/json
```

أسماء الصلاحيات أدناه حرفية؛ لا تحولها إلى camelCase، ولا ترسل `permission` في الطلب. الباك يتحقق من صلاحيات صاحب التوكن.

## الشركات

| العملية | Method | Endpoint | Permission |
| --- | --- | --- | --- |
| قائمة الشركات | GET | `/companies` | `all_companies` |
| تفاصيل الشركة وتجهيز شاشة التعديل | GET | `/companies/edit` | `edit_company` |
| إنشاء شركة | POST | `/companies/create` | `create_company` |
| تعديل شركة | PUT | `/companies/update` | `update_company` |
| حذف شركة | DELETE | `/companies/delete` | `delete_company` |

- `create_company` تسمح بإنشاء الشركة مع العميل والفرع الداخلي والعقد الاختياري في نفس الطلب. لا تحتاج معها `create_customer` أو `create_branch` أو `create_company_contract` لهذا الطلب.
- `currentContract` يرجع ضمن قائمة الشركات وتفاصيلها بنفس صلاحية الراوت، دون الحاجة إلى `all_company_contracts`.
- `edit_company` تسمح بقراءة التفاصيل فقط؛ تنفيذ التعديل نفسه يحتاج `update_company`. لو شاشة التعديل تجلب التفاصيل أولًا، تحتاج الصلاحيتين.

## العقود

| العملية | Method | Endpoint | Permission |
| --- | --- | --- | --- |
| سجل عقود الشركة | GET | `/company-contracts` | `all_company_contracts` |
| تفاصيل عقد | GET | `/company-contracts/show` | `all_company_contracts` |
| إنشاء عقد مستقل | POST | `/company-contracts/create` | `create_company_contract` |
| تعديل العقد الحالي | PUT | `/company-contracts/update` | `update_company_contract` |
| تجديد العقد | POST | `/company-contracts/renew` | `create_company_contract` |
| حذف العقد الحالي | DELETE | `/company-contracts/delete` | `delete_company_contract` |

لا توجد صلاحية منفصلة باسم `renew_company_contract` أو `show_company_contract`.

## التعامل في الواجهة

- أظهر أو أخفِ الأزرار والصفحات حسب الصلاحيات الفعلية للمستخدم؛ إخفاء الزر لا يغني عن تحقق الباك.
- وجود الصلاحية لا يلغي شروط العقد: وجود عقد ساري يمنع الإنشاء والتجديد، والتعديل والحذف للعقد الساري غير المحذوف فقط. تجديد العقد يحتاج عقدًا سابقًا منتهيًا غير محذوف.
- اعتمد على `currentContract` الساري لتوفير أزرار تعديل وحذف العقد، وليس كل صف حالته `1` في السجل؛ السجل قد يحتوي عقودًا محذوفة دون إظهار بيانات الحذف.
- `401`: التوكن مفقود أو غير صالح. `403`: المستخدم لا يملك الصلاحية. `409`: الصلاحية موجودة لكن العملية مخالفة لقواعد العقد.
- APIs الفروع موجودة في الباك فقط لهذا الفلو، ولا يحتاج الفرونت ربطها أو عرض أزرار لها.
- صلاحيات العقود تحتاج تشغيل seeder الخاص بها عند النشر؛ توكن الأدمن وحده لا يمنحها تلقائيًا. راجع [خطوات النشر](company-contracts-backend.md).

تفاصيل الـ requests والـ responses: [توثيق الفرونت بالعربي](companies-branches-contracts-api.ar.md).
