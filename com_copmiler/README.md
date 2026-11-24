# com_copmiler — WordPress to Joomla 6 Compiler

کامپوننت **com_copmiler** برای **جوملا 6 (PHP 8.3+ / 8.4)** طراحی شده است تا هر افزونه وردپرسی (`.zip`) را به‌صورت خودکار تحلیل کند، نوع دقیق معادل آن در جوملا 6 را تشخیص دهد (**Component**، **Plugin** با گروه دقیق، یا **Module**) و آن را به یک پکیج استاندارد و قابل نصب در جوملا 6 کامپایل کند.

## ویژگی‌های کلیدی

1. **تشخیص هوشمند نوع افزونه (Automatic Extension Classifier)**:
   - اگر افزونه وردپرس دارای منوهای مدیریت (`add_menu_page`/`add_submenu_page`/`add_options_page`)، Custom Post Type (`register_post_type`) یا جداول دیتابیس اختصاصی (`dbDelta`/`CREATE TABLE`) باشد ← **کامپوننت جوملا 6 (`com_*`)**
   - اگر افزونه وردپرس ویجت (`WP_Widget` / `register_widget`) تعریف کرده باشد ← **ماژول جوملا 6 (`mod_*`)** با استخراج خودکار فیلدهای فرم ویجت به `<config><fields name="params">`
   - اگر افزونه وردپرس مبتنی بر هوک‌ها (`add_action`/`add_filter`)، شورت‌کد (`add_shortcode`)، REST API، احراز هویت یا رویداد کاربر باشد ← **پلاگین جوملا 6 (`plg_<group>_*`)** با تشخیص خودکار گروه (`content`, `system`, `user`, `authentication`, `webservices`, `task`)

2. **دو حالت کامپایل (قابل انتخاب)**:
   - **حالت هیبریدی (Hybrid Mode — پیشنهادی)**: کد افزونه وردپرس را برای PHP 8.3/8.4 و جوملا 6 بازنویسی کرده و یک لایه سازگاری ایزوله و بومی جوملا 6 (`WpBridge`, `WpdbAdapter`, `WpCompatRegistry`) همراه آن بسته‌بندی می‌کند (مبتنی بر `DatabaseInterface`, `WebAssetManager`, `Joomla\Http\HttpFactory`, `MailerFactoryInterface`, `Session::getFormToken()`, `SubscriberInterface`).
   - **حالت بومی خالص (Native Mode)**: تبدیل استاتیک کد به ساختار خالص جوملا 6 بدون لایه Runtime Shim به‌همراه گزارش کامل مهاجرت (`MIGRATION_REPORT.md`).

3. **کاملاً مطابق استانداردهای جوملا 6**:
   - `declare(strict_types=1);` و فضای نام PSR-4
   - DI Service Provider (`services/provider.php`) بدون سازنده‌های منسوخ‌شده (`CMSPlugin` بدون آرگومان `$dispatcher`)
   - استفاده از `SubscriberInterface`, `DatabaseAwareTrait`, `AbstractModuleDispatcher`, `WebAssetManager`
   - رابط کاربری دوزبانه **فارسی (fa-IR با پشتیبانی RTL)** و **انگلیسی (en-GB)**

## ساخت پکیج قابل نصب در جوملا 6

```powershell
php build-package.php
```
فایل `dist/com_copmiler-1.0.0-joomla6.zip` ساخته می‌شود و مستقیماً از مسیر **System → Install → Extensions** در جوملا 6 قابل نصب است.

## استفاده از ابزار خط فرمان (CLI)

```powershell
php cli/compile.php path/to/wordpress-plugin.zip --out=./dist --mode=hybrid
```

## اجرای تست‌های خودکار

```powershell
php tests/run_tests.php
```
