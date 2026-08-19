<?php

namespace App\Services;

use App\Models\Client;
use App\Models\RadCheck;
use App\Models\RadGroupReply;
use App\Models\RadReply;
use App\Models\RadUserGroup;

/**
 * Keeps radcheck/radusergroup/radreply in sync with a client's row in
 * `clients`, matching the exact convention the legacy isp-panel already
 * used for every existing row (verified against real data before writing
 * this): radcheck gets a Cleartext-Password + an Expiration row
 * ("d M Y H:i:s", e.g. "22 Aug 2026 00:00:00"); radusergroup gets one row
 * with groupname = package; radreply gets a Mikrotik-Rate-Limit (copied
 * from radgroupreply for that package) + the same Expiration value. All
 * three tables key rows by `username` only (no client_id column), so a
 * username change must rename the RADIUS rows, not just resync them.
 */
class RadiusProvisioningService
{
    /** Full sync of every RADIUS row for this client — safe to call after any create/update. */
    public function sync(Client $client): void
    {
        $this->syncPassword($client);
        $this->syncExpiration($client);
        $this->syncGroup($client);
    }

    public function syncPassword(Client $client): void
    {
        RadCheck::updateOrCreate(
            ['username' => $client->username, 'attribute' => 'Cleartext-Password'],
            ['op' => ':=', 'value' => $client->password],
        );
    }

    public function syncExpiration(Client $client): void
    {
        // الوقت الكامل مقصود: الاشتراك ينتهي في نفس ساعة/دقيقة إنشائه أو
        // تجديده بالضبط، وليس عند منتصف ليل اليوم — يطابق FreeRADIUS الذي
        // يرفض أي محاولة تسجيل دخول بعد هذه اللحظة بالضبط.
        $expiration = $client->end_date->format('d M Y H:i:s');

        RadCheck::updateOrCreate(
            ['username' => $client->username, 'attribute' => 'Expiration'],
            ['op' => ':=', 'value' => $expiration],
        );

        RadReply::updateOrCreate(
            ['username' => $client->username, 'attribute' => 'Expiration'],
            ['op' => ':=', 'value' => $expiration],
        );
    }

    public function syncGroup(Client $client): void
    {
        RadUserGroup::updateOrCreate(
            ['username' => $client->username],
            ['groupname' => $client->package, 'priority' => 1],
        );

        $rateLimit = RadGroupReply::where('groupname', $client->package)
            ->where('attribute', 'Mikrotik-Rate-Limit')
            ->value('value');

        // لا يوجد حد سرعة افتراضي لهذه الباقة في radgroupreply (باقة حرة
        // كتبها الوكيل يدوياً ولا تطابق أياً من المجموعات الأربع المعروفة)
        // — نتجاهل هذا الحقل بدل تخمين قيمة قد تكون خاطئة.
        if ($rateLimit !== null) {
            RadReply::updateOrCreate(
                ['username' => $client->username, 'attribute' => 'Mikrotik-Rate-Limit'],
                ['op' => ':=', 'value' => $rateLimit],
            );
        }
    }

    /** ينقل صفوف RADIUS من اسم مستخدم قديم إلى الجديد عند تعديل اسم المستخدم. */
    public function renameUsername(string $oldUsername, string $newUsername): void
    {
        if ($oldUsername === $newUsername) {
            return;
        }

        RadCheck::where('username', $oldUsername)->update(['username' => $newUsername]);
        RadReply::where('username', $oldUsername)->update(['username' => $newUsername]);
        RadUserGroup::where('username', $oldUsername)->update(['username' => $newUsername]);
    }

    /** يحذف كل صفوف RADIUS لهذا المستخدم — يمنع بقاء بيانات دخول فعّالة لعميل محذوف. */
    public function deprovision(string $username): void
    {
        RadCheck::where('username', $username)->delete();
        RadReply::where('username', $username)->delete();
        RadUserGroup::where('username', $username)->delete();
    }
}
