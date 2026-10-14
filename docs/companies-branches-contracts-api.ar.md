# الشركات والعملاء والعقود: طلبات وردود الفرونت

> هذا الملف يطابق تنفيذ الباك في هذا الفرع. يلزم نشر الكود وتشغيل migration وصلاحيات العقود قبل استخدامه على السيرفر. الأمثلة كاملة من حيث الحقول، والقيم والأرقام للتوضيح.

## إعداد الطلبات

الرابط الأساسي: `https://customerservicebe.testingelmo.com/api/v1/admin`

```http
Authorization: Bearer ADMIN_ACCESS_TOKEN
Accept: application/json
Content-Type: application/json
```

- GET وDELETE: القيم في Query Params. POST وPUT: القيم في JSON Body.
- التواريخ بصيغة `YYYY-MM-DD`. استخدم أسماء الـ keys كما في الأمثلة؛ `firstname` و`lastname` بنفس الكتابة الموجودة حاليًا في التطبيق، وباقي الأسماء المركبة مثل `companyId` و`startDate` بنظام camelCase.
- حالة الشركة والعميل: `1` نشط، `0` غير نشط. حالة العقد: `1` ساري، `0` منتهٍ.
- الفروع تدار داخليًا: ينشئ الباك الفرع الافتراضي باسم الشركة، ويحدث اسمه داخليًا عند تغيير اسم الشركة. الفرونت لا يرسل أو يعرض أي حقول تخص الفروع في هذا الفلو.
- APIs الفروع موجودة في الباك، لكنها خارج نطاق ربط الفرونت، لذلك لا تتضمن هذه الوثيقة طلباتها أو ردودها.
- إعداد نظام التيكت القديم يُضبط داخليًا على `0` للشركات الجديدة، دون تعديل قيم الشركات القديمة. لا يرسله الفرونت ولا يظهر في الردود.
- جميع أمثلة النجاح التالية ترجع HTTP `200`.
- بيانات الحذف داخلية؛ حقل `deletedAt` لا يظهر في أي response، بما فيها سجل العقود وتفاصيلها.

## 1. قائمة الشركات

### GET /companies

**Request — Query Params:**

كل القيم اختيارية: `page` افتراضيًا `1`، و`pageSize` افتراضيًا `10`، و`filter[search]` للبحث بالاسم، و`filter[status]` لحالة الشركة `0` أو `1`. حجم الصفحة من 1 إلى 100.

```http
GET /companies?page=1&pageSize=10&filter[search]=Arca&filter[status]=1
```

**Response 200:**

```json
{
  "result": {
    "companies": [
      {
        "companyId": 4,
        "name": "Arca Pro",
        "status": 1,
        "currentContract": {
          "contractId": 21,
          "companyId": 4,
          "startDate": "2026-10-12",
          "endDate": "2026-11-10",
          "status": 1
        }
      }
    ]
  },
  "pagination": {
    "total": 1,
    "count": 1,
    "perPage": 10,
    "currentPage": 1,
    "totalPages": 1
  }
}
```

القائمة تعرض بيانات الشركة والعقد فقط. اعرض حالة العقد وبدايته ونهايته من `currentContract`. عند عدم وجود عقد مناسب، تكون `currentContract: null`. عند عدم وجود شركات، تكون `result.companies: []` و`total: 0` و`count: 0`.

## 2. تفاصيل الشركة

### GET /companies/show

**Request — Query Params:**

مطلوب: `companyId`.

```http
GET /companies/show?companyId=4
```

**Response 200:**

```json
{
  "companyId": 4,
  "name": "Arca Pro",
  "status": 1,
  "currentContract": {
    "contractId": 21,
    "companyId": 4,
    "startDate": "2026-10-12",
    "endDate": "2026-11-10",
    "status": 1
  }
}
```

### GET /companies/edit

راوت التفاصيل القديم يظل متاحًا للتوافق.

**Request — Query Params:**

مطلوب: `companyId`.

```http
GET /companies/edit?companyId=4
```

**Response 200:**

```json
{
  "companyId": 4,
  "name": "Arca Pro",
  "status": 1,
  "currentContract": {
    "contractId": 21,
    "companyId": 4,
    "startDate": "2026-10-12",
    "endDate": "2026-11-10",
    "status": 1
  }
}
```

## 3. إنشاء شركة وعميل وعقد اختياري

### POST /companies/create

**Request — JSON Body:**

مطلوب: `name` غير مكرر، و`status` للشركة، وobject باسم `customer` يحتوي على بيانات العميل التالي ذكرها:

| الحقل | المطلوب |
| --- | --- |
| `customer.firstname` | الاسم الأول، نص مطلوب |
| `customer.lastname` | الاسم الأخير، نص مطلوب |
| `customer.username` | اسم دخول مطلوب وغير مكرر بين العملاء |
| `customer.email` | اختياري؛ احذفه أو أرسله `null`، وإذا أرسلته كنص فيجب أن يكون بريدًا صحيحًا |
| `customer.pin` | PIN مطلوب ويُرسل كنص للحفاظ على الأصفار في بدايته، مثل `"0705"` |

لا ترسل ID أو حالة للعميل. الباك يربطه بالشركة الجديدة ويُنشئه نشطًا بقيمة `status: 1`. عند عدم إرسال البريد يرجع `data.customer.email: null`. الـ PIN نص بطول أقصاه 255 حرفًا، وكذلك أسماء العميل واسم الدخول؛ لا يرجع في الرد.

اختياري: object باسم `contract` لإنشاء العقد مع الشركة. إذا أرسلته، يجب أن يحتوي على `startDate` و`endDate` صالحين؛ لا ترسله فارغًا أو `null`، ولا ترسل بداخله `companyId` أو `contractId` أو `status`. الباك يربطه بالشركة الجديدة ويحسب حالته.

```json
{
  "name": "Arca Pro",
  "status": 1,
  "customer": {
    "firstname": "Mario",
    "lastname": "Rossi",
    "username": "mario.rossi",
    "email": "mario@example.com",
    "pin": "0705"
  },
  "contract": {
    "startDate": "2026-10-12",
    "endDate": "2026-11-10"
  }
}
```

**Response 200:**

```json
{
  "message": "Company created successfully.",
  "data": {
    "companyId": 4,
    "name": "Arca Pro",
    "status": 1,
    "customer": {
      "customerId": 34,
      "companyId": 4,
      "firstname": "Mario",
      "lastname": "Rossi",
      "username": "mario.rossi",
      "email": "mario@example.com",
      "status": 1
    },
    "currentContract": {
      "contractId": 21,
      "companyId": 4,
      "startDate": "2026-10-12",
      "endDate": "2026-11-10",
      "status": 1
    }
  }
}
```

الشركة والفرع الداخلي والعميل والعقد المرسل تُنشأ في transaction واحدة؛ لو فشل أي جزء، لا يُحفظ أي منها. مثال: تكرار اسم الدخول يمنع حفظ الشركة أيضًا. العميل المُنشأ يرجع داخل `data.customer`، لكن `pin` لا يرجع في الرد.

أخطاء بيانات العميل ترجع `422` بمفاتيح مثل `customer.username` و`customer.email` و`customer.pin`. أخطاء تواريخ العقد تستخدم `contract.startDate` و`contract.endDate`.

لإنشاء الشركة دون عقد، احذف حقل `contract` من الطلب. عندها يرجع نفس شكل الرد مع `data.currentContract: null`، ويمكن إنشاء العقد لاحقًا من راوت العقود المنفصل باستخدام `data.companyId`.

## 4. تعديل الشركة

### PUT /companies/update

**Request — JSON Body:**

مطلوب: `companyId` و`name` و`status` فقط. لا ترسل بيانات فروع أو عميل أو عقد أو إعداد النظام القديم.

```json
{ "companyId": 4, "name": "Arca Pro Updated", "status": 1 }
```

**Response 200:**

```json
{ "message": "Company updated successfully." }
```

تحديث اسم الفرع الافتراضي يتم داخليًا عند تغيير اسم الشركة دون أي طلب إضافي من الفرونت. تعديل الشركة لا ينشئ عميلًا آخر ولا يعدل بيانات العميل المُنشأ؛ بيانات `customer` مطلوبة عند الإنشاء فقط. إدارة بيانات العملاء لاحقًا تظل من موديول العملاء الحالي.

## 5. حذف الشركة

### DELETE /companies/delete

**Request — Query Params:**

مطلوب: `companyId`. دون Body.

```http
DELETE /companies/delete?companyId=4
```

**Response 200:**

```json
{ "message": "Company deleted successfully." }
```


## 6. سجل عقود الشركة

### GET /company-contracts

**Request — Query Params:**

مطلوب: `companyId`. اختياري: `page` و`pageSize`، افتراضيًا `1` و`10`.

```http
GET /company-contracts?companyId=4&page=1&pageSize=10
```

**Response 200:**

```json
{
  "result": {
    "contracts": [
      {
        "contractId": 21,
        "companyId": 4,
        "startDate": "2026-10-12",
        "endDate": "2026-11-10",
        "status": 1
      },
      {
        "contractId": 20,
        "companyId": 4,
        "startDate": "2026-09-01",
        "endDate": "2026-09-30",
        "status": 0
      }
    ]
  },
  "pagination": {
    "total": 2,
    "count": 2,
    "perPage": 10,
    "currentPage": 1,
    "totalPages": 1
  }
}
```

الترتيب من الأحدث إنشاءً إلى الأقدم، دون إرجاع أي بيانات للحذف. عند عدم وجود عقود تكون `result.contracts: []`، و`total: 0` و`count: 0` و`totalPages: 1`. إجراءات تعديل وحذف العقد تخص `currentContract` الساري فقط، وليست متاحة لكل صف في السجل لمجرد أن `status` يساوي `1`.

## 7. تفاصيل عقد

### GET /company-contracts/show

**Request — Query Params:**

مطلوب: `companyId` و`contractId`.

```http
GET /company-contracts/show?companyId=4&contractId=20
```

**Response 200:**

```json
{
  "contractId": 20,
  "companyId": 4,
  "startDate": "2026-09-01",
  "endDate": "2026-09-30",
  "status": 0
}
```

## 8. إنشاء عقد

### POST /company-contracts/create

**Request — JSON Body:**

مطلوب: `companyId` و`startDate` و`endDate`. لا ترسل `status`؛ الباك يحسبه.

```json
{ "companyId": 4, "startDate": "2026-10-12", "endDate": "2026-11-10" }
```

**Response 200:**

```json
{
  "message": "Contract created successfully.",
  "data": {
    "contractId": 21,
    "companyId": 4,
    "startDate": "2026-10-12",
    "endDate": "2026-11-10",
    "status": 1
  }
}
```

وجود عقد ساري للشركة يمنع الإنشاء ويرجع `409`.

## 9. تعديل العقد الحالي

### PUT /company-contracts/update

**Request — JSON Body:**

مطلوب: `companyId` و`contractId` و`startDate` و`endDate`.

```json
{ "companyId": 4, "contractId": 21, "startDate": "2026-10-12", "endDate": "2026-12-10" }
```

**Response 200:**

```json
{
  "message": "Contract updated successfully.",
  "data": {
    "contractId": 21,
    "companyId": 4,
    "startDate": "2026-10-12",
    "endDate": "2026-12-10",
    "status": 1
  }
}
```

التعديل على العقد الساري نفسه وبنفس الـ ID، وليس إنشاء عقد آخر.

## 10. تجديد العقد

### POST /company-contracts/renew

**Request — JSON Body:**

مطلوب: `companyId` فقط. لا ترسل تواريخ أو ID العقد السابق.

```json
{ "companyId": 4 }
```

**Response 200:**

مثال مستقل: التجديد يوم `2026-10-12` لعقد سابق منتهٍ مدته 30 يومًا.

```json
{
  "message": "Contract renewed successfully.",
  "data": {
    "contractId": 22,
    "companyId": 4,
    "startDate": "2026-10-12",
    "endDate": "2026-11-10",
    "status": 1
  }
}
```

العقد الجديد مستقل عن السابق، ولا يُحفظ ربط بينهما. لو يوجد عقد ساري أو لا يوجد عقد سابق صالح للتجديد، يرجع `409`.

## 11. حذف العقد الحالي

### DELETE /company-contracts/delete

**Request — Query Params:**

مطلوب: `companyId` و`contractId`. دون Body.

```http
DELETE /company-contracts/delete?companyId=4&contractId=21
```

**Response 200:**

```json
{ "message": "Contract deleted successfully." }
```

## قواعد مختصرة للعقود

- العقد يحتوي على تاريخ البداية والنهاية، وحالته ساري أو منتهٍ ويحسبها الباك.
- وجود عقد ساري يمنع الإنشاء والتجديد؛ المتاح تعديل الحالي أو حذفه.
- التجديد ينشئ عقدًا جديدًا من اليوم بنفس مدة السابق، ويحافظ على العقد القديم في السجل.
- بعد أي عملية، حدّث جدول الشركات وتفاصيل الشركة وسجل العقود.
- الباك يفرض القيود حتى مع وصول طلبات متزامنة.

القواعد المطبقة:
- المدة بالأيام شاملة يوم البداية والنهاية. العقد ساري حتى نهاية يوم انتهائه. توقيت التطبيق في الباك هو المرجع لتاريخ اليوم.
- البداية المستقبلية غير مقبولة، والنهاية لا تسبق البداية. تعديل العقد الساري ليصبح منتهيًا يجعله للعرض فقط بعد ذلك.
- `currentContract` يرجع الساري، وإلا أحدث عقد منتهٍ غير محذوف، وإلا `null`.
- التجديد يعتمد على أحدث عقد منتهٍ غير محذوف. لو لم يوجد، استخدم الإنشاء.
- حذف العقد soft delete؛ المحذوف يبقى في السجل دون إظهار بيانات الحذف، ولا يدخل في `currentContract` ولا يمنع الإنشاء ولا يُستخدم للتجديد. العقود المنتهية والمحذوفة للعرض فقط.

## الأخطاء والتوافق

| الكود | المعنى |
| --- | --- |
| 401 | توكن الأدمن غير صالح |
| 403 | لا توجد صلاحية |
| 404 | العنصر غير موجود، أو العقد لا يتبع الشركة المحددة |
| 409 | العملية تخالف قواعد العقد |
| 422 | بيانات غير صحيحة |
| 429 | طلبات كثيرة؛ انتظر قبل إعادة المحاولة |
| 500 | خطأ سيرفر؛ لا تفترض نجاح العملية |

مثال خطأ `422`:

```json
{
  "message": "The end date field must be a date after or equal to start date.",
  "errors": { "endDate": ["The end date field must be a date after or equal to start date."] }
}
```

مثال خطأ `409` عند وجود عقد ساري:

```json
{
  "message": "The company already has an active contract.",
  "errors": { "companyId": ["Update or delete the active contract before creating or renewing a contract."] }
}
```

- اربط أخطاء إنشاء العميل بمسار الحقل مثل `customer.username`، وأخطاء العقد المرسل مع الشركة مثل `contract.endDate`. اعتمد على الكود والـ keys لا نص الرسالة.
- Pagination يستخدم `perPage` و`currentPage` و`totalPages` بدل الصيغة القديمة، والتحقق من بيانات الشركة والفروع يرجع `422` بدل `401`. يلزم نشر الفرونت والباك المتوافقين معًا. نص رسائل التحقق يتغير حسب لغة السيرفر.
- الفروع مسؤولية الباك في هذا الفلو؛ الفرونت لا يبني لها شاشات ولا يرسل طلبات لإدارتها. الإبقاء على APIs الفروع في الباك لا يعني إتاحتها ضمن واجهة الفرونت المطلوبة.
