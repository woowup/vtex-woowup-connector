<?php

namespace WoowUpConnectors\Stages;

class VTEXConfig
{
    private const VTEX_PARENTS = [
        1384, 1389, 1030, 1262, 1263, 1264, 1294, 912, 849, 1421,
        1438, 1442, 1074, 1440, 1378, 1490, 1494, 1455, 1333
    ];

    private const VTEX_CHILDS = [
        1141, 1129, 1131, 1127, 1122, 1125, 1126, 1110, 896, 959,
        1162, 1172, 1220, 1219, 828, 1390, 1369, 1410, 1247, 1344,
        1380, 1269, 899, 993, 1080, 1135, 1169, 1210, 1225, 1226,
        1229, 1231, 1233, 1245, 1253, 1254, 1255, 1261, 1282, 1214,
        1275, 1300, 1285, 1302, 1318, 1319, 1317, 1310, 1324, 1338,
        1335, 1339, 1347, 1351, 1248, 1356, 1357, 1288, 1361, 1388,
        1394, 1304, 1407, 1405, 1399, 1400, 1401, 1437, 1314, 1460,
        1472, 1454, 1476, 1486, 1453, 1462, 1461, 1463, 1506, 1547,
        1493, 1553, 1558, 1561, 1551, 1587, 1636, 1647, 1656, 1673,
        1683, 1702, 1703, 1712, 1716, 1729, 1774, 1779, 1777, 1767,
        1818, 1821, 1823, 1815, 1828, 1839, 1819, 1843, 1844, 1845
    ];

    private const STARTING_NEW_ACCOUNTS_APP_ID = 1530;

    private const TEST_FEATURE_APP_IDS = [1455, 1261];

    private const SEARCH_FOR_AVAILABLE_PRODUCTS_ACCOUNTS = [1495];

    private const DOWNLOAD_INACTIVE_PRODUCTS = [1608, 1682, 1294, 1731, 1696];

    public static function mapsChildProducts(int $appId): bool
    {
        return in_array($appId, self::VTEX_CHILDS, true);
    }

    public static function getStartingIdNewAccounts(): int
    {
        return self::STARTING_NEW_ACCOUNTS_APP_ID;
    }

    public static function getTestFeaturesAccounts(): array
    {
        return self::TEST_FEATURE_APP_IDS;
    }

    public static function downloadInactiveProducts(int $appId): bool
    {
        return in_array($appId, self::DOWNLOAD_INACTIVE_PRODUCTS, true);
    }
}
