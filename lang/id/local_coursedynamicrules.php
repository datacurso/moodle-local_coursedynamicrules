<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin strings are defined here.
 *
 * @package     local_coursedynamicrules
 * @category    string
 * @copyright   2025 Wilber Narvaez <https://datacurso.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Tindakan';
$string['actions_help'] = 'Tindakan digunakan untuk menentukan apa yang akan dijalankan ketika ketentuan aturan terpenuhi';
$string['addactions'] = 'Tambah tindakan';
$string['addconditions'] = 'Tambah ketentuan';
$string['after'] = 'Setelah';
$string['allcourseactivitymodules'] = 'Semua modul aktivitas kursus';
$string['availableplaceholders'] = 'Placeholder yang tersedia';
$string['backtolistrules'] = 'Kembali ke daftar aturan';
$string['basedate'] = 'Tanggal dasar';
$string['basedate_help'] = 'Pilih tanggal acuan untuk mengevaluasi ketidakaktifan:

* **Sejak tanggal pendaftaran**: Dihitung sejak pengguna terdaftar.
* **Sejak tanggal mulai kursus**: Dihitung sejak tanggal mulai kursus.
* **Sejak aturan diaktifkan**: Dihitung sejak aturan pertama kali diaktifkan. Menjeda dan mengaktifkan kembali aturan tidak mengatur ulang hitungan.';
$string['before'] = 'Sebelum';
$string['checklicensekey'] = 'Periksa kunci lisensi';
$string['complete_activity'] = 'Aktivitas selesai';
$string['complete_activity_condition_info'] = 'Ketentuan ini akan memeriksa pengguna mana yang telah menyelesaikan modul aktivitas yang dipilih.';
$string['complete_activity_description'] = 'Pengguna yang telah menyelesaikan modul aktivitas kursus "{$a->moddescription}"';
$string['completiondate'] = 'Tanggal penyelesaian';
$string['conditions'] = 'Ketentuan';
$string['conditions_help'] = 'Ketentuan digunakan untuk mendefinisikan syarat yang harus dipenuhi agar tindakan aturan dijalankan';
$string['copiedtoclipboard'] = 'Disalin ke papan klip';
$string['copytoclipboard'] = 'Salin ke papan klip';
$string['course_inactivity'] = 'Ketidakaktifan kursus dalam interval waktu';
$string['course_inactivity_custom_description'] = 'Pengguna tanpa aktivitas di kursus selama interval {$a->intervals} {$a->unit} sejak {$a->basedate}';
$string['course_inactivity_info'] = 'Ketentuan ini akan memeriksa pengguna yang tidak memiliki aktivitas di kursus dalam interval waktu yang ditentukan.';
$string['course_inactivity_recurring_description'] = 'Pengguna tanpa aktivitas di kursus pada interval berulang {$a->intervals} {$a->unit} sejak {$a->basedate}';
$string['course_inactivity_task'] = 'Tugas ketidakaktifan kursus';
$string['coursedynamicrules:createaction'] = 'Buat tindakan';
$string['coursedynamicrules:createcondition'] = 'Buat ketentuan';
$string['coursedynamicrules:createrule'] = 'Buat aturan';
$string['coursedynamicrules:deleteaction'] = 'Hapus tindakan';
$string['coursedynamicrules:deletecondition'] = 'Hapus ketentuan';
$string['coursedynamicrules:deleterule'] = 'Hapus aturan';
$string['coursedynamicrules:manageaction'] = 'Kelola tindakan';
$string['coursedynamicrules:managecondition'] = 'Kelola ketentuan';
$string['coursedynamicrules:managerule'] = 'Kelola aturan';
$string['coursedynamicrules:notification'] = 'Kirim notifikasi';
$string['coursedynamicrules:updateaction'] = 'Perbarui tindakan';
$string['coursedynamicrules:updatecondition'] = 'Perbarui ketentuan';
$string['coursedynamicrules:updaterule'] = 'Perbarui aturan';
$string['coursedynamicrules:viewaction'] = 'Lihat tindakan';
$string['coursedynamicrules:viewcondition'] = 'Lihat ketentuan';
$string['coursedynamicrules:viewrule'] = 'Lihat aturan';
$string['courselink'] = 'Tautan kursus';
$string['coursemoduleelementnotfound'] = 'Elemen pemilihan modul aktivitas kursus tidak ditemukan.';
$string['coursename'] = 'Nama kursus';
$string['coursestartdate'] = 'Tanggal mulai kursus';
$string['createaiactivity'] = 'Buat aktivitas penguatan AI';
$string['createaiactivity_action_info'] = 'Tindakan ini akan meminta layanan Datacurso AI untuk membuat aktivitas penguatan yang dipersonalisasi bagi pengguna yang memenuhi ketentuan aturan.';
$string['createaiactivity_beforemod'] = 'Tempatkan sebelum aktivitas';
$string['createaiactivity_beforemod_help'] = 'Pilih aktivitas yang harus didahului sumber baru, atau biarkan opsi default untuk menambahkannya di akhir bagian.';
$string['createaiactivity_beforemod_none'] = 'Jangan posisikan sebelum aktivitas lain';
$string['createaiactivity_description'] = 'Hasilkan aktivitas penguatan AI di bagian "{$a->section}" menggunakan prompt "{$a->prompt}"';
$string['createaiactivity_generateimages'] = 'Hasilkan gambar dengan AI';
$string['createaiactivity_generateimages_label'] = 'Izinkan AI menyertakan gambar yang dihasilkan saat didukung.';
$string['createaiactivity_placeholders_info'] = 'Placeholder yang tersedia: <code>{$a->coursename}</code>, <code>{$a->courseurl}</code>, <code>{$a->fullname}</code>, <code>{$a->firstname}</code>, <code>{$a->lastname}</code>.';
$string['createaiactivity_prompt'] = 'Prompt AI';
$string['createaiactivity_prompt_help'] = 'Tulis instruksi yang akan dikirim ke layanan AI. Anda dapat menyertakan placeholder yang akan diganti sebelum pengiriman.';
$string['createaiactivity_section'] = 'Bagian kursus';
$string['createrule'] = 'Buat aturan';
$string['customintervals'] = 'Interval khusus';
$string['customintervals_help'] = 'Masukkan angka yang dipisahkan koma yang mewakili periode ketidakaktifan (mis., "7,14,30").';
$string['datacurso'] = 'Datacurso';
$string['date_from_course_start'] = 'Sejak tanggal mulai kursus';
$string['date_from_enrollment'] = 'Sejak tanggal pendaftaran';
$string['date_from_now'] = 'Sejak aturan diaktifkan';
$string['days'] = 'Hari';
$string['deleteactioncheck'] = 'Apakah Anda benar-benar yakin ingin menghapus tindakan ini sepenuhnya?';
$string['deletecondition'] = 'Hapus ketentuan';
$string['deleteconditioncheck'] = 'Apakah Anda benar-benar yakin ingin menghapus ketentuan ini sepenuhnya?';
$string['deletedaction'] = 'Tindakan dihapus <b>{$a}</b>';
$string['deletedcondition'] = 'Ketentuan dihapus <b>{$a}</b>';
$string['deletedrule'] = 'Aturan dihapus <b>{$a}</b>';
$string['deleterule'] = 'Hapus aturan';
$string['deleterulecheck'] = 'Apakah Anda benar‑benar yakin ingin menghapus aturan ini sepenuhnya?';
$string['deletingcondition'] = 'Menghapus ketentuan "{$a}"';
$string['deletingrule'] = 'Menghapus aturan "{$a}"';
$string['description'] = 'Deskripsi';
$string['editactions'] = 'Edit tindakan';
$string['editconditions'] = 'Edit ketentuan';
$string['editrule'] = 'Edit aturan';
$string['enableactivity'] = 'Aktifkan aktivitas';
$string['enableactivity_action_info'] = 'Tindakan ini akan mengaktifkan modul aktivitas yang dipilih bagi pengguna yang memenuhi kriteria aturan.';
$string['enableactivity_description'] = 'Aktifkan aktivitas "{$a}"';
$string['enablegradegreaterthanorequal_help'] = 'Aktifkan nilai lebih besar atau sama dengan';
$string['enablegradelessthan'] = 'Aktifkan nilai kurang dari';
$string['enrollmentdate'] = 'Tanggal pendaftaran';
$string['errorgradeoutofrange'] = 'Nilai harus antara {$a->min} dan {$a->max}.';
$string['errormaxgradeexceeded'] = 'Nilai tidak boleh melebihi nilai maksimum untuk aktivitas.';
$string['errornegativegrade'] = 'Nilai harus 0 atau lebih.';
$string['expectedcompletiondate'] = 'Tanggal penyelesaian yang diharapkan';
$string['firstname'] = 'Nama depan pengguna';
$string['fullname'] = 'Nama lengkap pengguna';
$string['generalsettings'] = 'Pengaturan umum';
$string['grade'] = 'Nilai';
$string['grade_in_activity'] = 'Nilai pada aktivitas';
$string['grade_in_activity_condition_info'] = 'Ketentuan ini akan memeriksa pengguna yang memperoleh nilai yang ditentukan pada modul aktivitas yang dipilih.';
$string['grade_in_activity_description'] = 'Untuk "{$a->moddescription}", nilai berikut harus diperoleh: {$a->gradestring}';
$string['gradegreaterthanorequal'] = 'harus &#x2265;';
$string['gradegreaterthanorequal_help'] = 'Ketentuan terpenuhi jika nilai pengguna lebih besar atau sama dengan nilai yang ditentukan.';
$string['gradegreaterthanorequalvalue'] = '&#x2265; {$a}';
$string['gradelessthan'] = 'harus <';
$string['gradelessthan_help'] = 'Ketentuan terpenuhi jika nilai pengguna kurang dari nilai yang ditentukan.';
$string['gradelessthanvalue'] = '< {$a}';
$string['hours'] = 'Jam';
$string['intervaltype'] = 'Jenis interval';
$string['intervaltype_help'] = 'Pilih cara interval akan dievaluasi:

* **Interval khusus**: Tambahkan nilai yang dipisahkan koma (mis., 7,14,30) untuk mengevaluasi ketidakaktifan pada titik waktu tertentu.
* **Interval berulang**: Mengevaluasi ketidakaktifan pada interval berulang (mis., setiap 7 hari).';
$string['intervalunit'] = 'Satuan waktu';
$string['intervalunit_help'] = 'Pilih satuan waktu untuk interval.';
$string['invalidbasedate'] = 'Jenis tanggal dasar tidak valid {$a}';
$string['invalidruleid'] = 'ID aturan tidak valid';
$string['lastname'] = 'Nama belakang pengguna';
$string['licensekey'] = 'Kunci lisensi';
$string['licensekey_desc'] = 'Kunci lisensi diperlukan untuk menggunakan plugin ini';
$string['licensekeycompany'] = 'Kunci lisensi untuk: {$a}';
$string['licensekeycompany_desc'] = 'Kunci lisensi diperlukan untuk menggunakan plugin ini untuk perusahaan: {$a}';
$string['licensekeyinvalid'] = 'Kunci lisensi telah kedaluwarsa atau tidak valid. Silakan kunjungi <a href="https://shop.datacurso.com/clientarea.php" target="_blank">Shop Datacurso</a> untuk memperbarui atau membeli kunci baru.';
$string['licensekeyvalid'] = 'Kunci lisensi valid';
$string['messagebody'] = 'Isi';
$string['messagebody_help'] = 'Placeholder berikut dapat disertakan dalam pesan:

* Nama kursus {$a->coursename}
* Nama lengkap pengguna {$a->fullname}
* Nama depan pengguna {$a->firstname}
* Nama belakang pengguna {$a->lastname}
* Nama modul aktivitas kursus {$a->modulename}
* Nama instansi modul aktivitas kursus {$a->moduleinstancename}';
$string['messageprovider:smart_rules_ai_notification'] = 'Notifikasi Smart Rules AI';
$string['messagesubject'] = 'Subjek';
$string['minutes'] = 'Menit';
$string['missing_plugins_warning'] = '🔔 Tingkatkan notifikasi Anda! Plugin <strong>Datacurso Message Hub</strong> kami memungkinkan Anda mengirim notifikasi melalui WhatsApp dan SMS menggunakan penyedia seperti Twilio.
<br>
<a href="https://shop.datacurso.com/clientarea.php" target="_blank">Klik di sini untuk membeli dan mengaktifkannya sekarang!</a>';
$string['moduleinstancename'] = 'Nama instansi modul aktivitas kursus';
$string['modulename'] = 'Nama modul aktivitas kursus';
$string['months'] = 'Bulan';
$string['mustselectonerole'] = 'Anda harus memilih setidaknya satu peran.';
$string['name'] = 'Nama';
$string['no_complete_activity'] = 'Aktivitas belum selesai';
$string['no_complete_activity_condition_info'] = 'Ketentuan ini akan memeriksa pengguna yang belum menyelesaikan modul aktivitas yang dipilih setelah tanggal yang ditentukan.';
$string['no_complete_activity_description'] = 'Pengguna yang belum menyelesaikan modul aktivitas kursus "{$a->moddescription}" setelah {$a->expectedcompletiondate}';
$string['no_complete_activity_task'] = 'Tugas aktivitas belum selesai';
$string['no_course_access'] = 'Tidak ada akses ke kursus';
$string['no_course_access_condition_info'] = 'Ketentuan ini akan memeriksa pengguna yang tidak mengakses kursus ini dalam jangka waktu yang ditentukan.';
$string['no_course_access_description'] = 'Pengguna yang lebih dari {$a->periodvalue} {$a->periodunit} tanpa mengakses kursus ini.';
$string['no_course_access_task'] = 'Tugas tanpa akses kursus';
$string['notification_action_info'] = 'Tindakan ini akan mengirim notifikasi kepada pengguna yang memenuhi kriteria aturan.';
$string['now'] = 'Saat aturan diaktifkan';
$string['passgrade'] = 'Penyelesaian aktivitas dengan nilai lulus';
$string['passgrade_condition_info'] = 'Ketentuan ini akan memeriksa pengguna yang menyelesaikan modul aktivitas yang dipilih dengan nilai lulus.';
$string['passgrade_description'] = 'Pengguna yang telah menyelesaikan modul aktivitas kursus "{$a}" dengan nilai lulus';
$string['period'] = 'Periode';
$string['period_help'] = 'Jumlah waktu minimum seorang pengguna tidak boleh mengakses kursus.';
$string['plugin_disabled'] = 'Tindakan ini memerlukan plugin <strong>{$a->pluginname}</strong> diaktifkan. Silakan buka halaman <a href="{$a->enableurl}" target="_blank">{$a->enableurl}</a>, cari <strong>{$a->visiblename}</strong> dan aktifkan.';
$string['plugin_missing'] = 'Tindakan ini memerlukan plugin <strong>{$a->pluginname}</strong> terpasang dan diaktifkan. Silakan unduh dari <a href="{$a->downloadurl}" target="_blank">{$a->downloadurl}</a> dan pasang.';
$string['pluginname'] = 'Smart Rules AI';
$string['pluginnotavailable'] = 'Plugin ini tidak tersedia karena lisensi produk telah kedaluwarsa atau tidak valid. Silakan kunjungi <a href="https://shop.datacurso.com/clientarea.php" target="_blank">Shop Datacurso</a> untuk memperbarui atau membeli lisensi baru.';
$string['provider_not_enabled_warning'] = 'Aktifkan notifikasi dengan <strong>Datacurso Message Hub</strong> agar tindakan ini dapat mengirim notifikasi melalui WhatsApp dan SMS menggunakan penyedia seperti Twilio.
Anda dapat mengaktifkannya dari <a href="{$a}" target="_blank">Pengaturan notifikasi</a> dengan mencari <strong>Smart Rules AI notification</strong>.
<br>
<a href="https://docs.datacurso.com/index.php?title=Message_Hub" target="_blank">Lihat dokumentasi untuk informasi lebih lanjut.</a>';
$string['recurringinterval'] = 'Interval berulang';
$string['recurringinterval_help'] = 'Masukkan nilai numerik yang mewakili interval ketidakaktifan berulang (mis., "7" untuk setiap 7 hari ketidakaktifan).';
$string['rolestonotify'] = 'Peran yang diberi notifikasi';
$string['rolestonotify_help'] = 'Pilih peran yang harus dimiliki pengguna untuk menerima notifikasi. Anda harus memilih setidaknya satu.';
$string['ruleactive'] = 'Aktif';
$string['ruleactive_help'] = 'Aktifkan atau nonaktifkan aturan';
$string['ruleadd'] = 'Tambah aturan';
$string['ruleaddedsuccessfully'] = 'Aturan berhasil ditambahkan';
$string['ruleinactive'] = 'Tidak aktif';
$string['rules'] = 'Aturan';
$string['rules_help'] = 'Aturan digunakan untuk mendefinisikan serangkaian ketentuan dan tindakan yang akan dijalankan';
$string['ruleupdatedsuccessfully'] = 'Aturan berhasil diperbarui';
$string['searchcourseactivitymodules'] = 'Cari modul aktivitas kursus';
$string['sendnotification'] = 'Kirim notifikasi';
$string['sendnotification_description'] = 'Kirim notifikasi "{$a}" kepada pengguna';
$string['typemissing'] = 'Nilai "type" hilang';
$string['weeks'] = 'Minggu';
