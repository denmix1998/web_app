<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Устанавливаем даты по умолчанию
$start = $_GET['start_date'] ?? '2000-01-01';
$end = $_GET['end_date'] ?? date('Y-m-d');

// Получаем данные о приходе (добавлении)
$inStmt = $pdo->prepare("
    SELECT material_name, SUM(quantity) as total_in 
    FROM consumables_list 
    WHERE record_date BETWEEN ? AND ? 
    GROUP BY material_name
");
$inStmt->execute([$start, $end]);
$inData = $inStmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем данные о расходе (выдаче)
$outStmt = $pdo->prepare("
    SELECT model as material_name, SUM(quantity) as total_out 
    FROM cartridges 
    WHERE date BETWEEN ? AND ? 
    GROUP BY model
");
$outStmt->execute([$start, $end]);
$outData = $outStmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем текущие остатки
$currentStmt = $pdo->prepare("
    SELECT material_name, quantity 
    FROM consumables_list
");
$currentStmt->execute();
$currentData = $currentStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Формируем общий список материалов
$materials = [];
$inMap = [];
$outMap = [];

foreach ($inData as $row) {
    $materials[$row['material_name']] = true;
    $inMap[$row['material_name']] = $row['total_in'];
}

foreach ($outData as $row) {
    $materials[$row['material_name']] = true;
    $outMap[$row['material_name']] = $row['total_out'];
}

ksort($materials);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Полная сводка</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        #summaryChart {
            width: 100%;
            max-width: 800px;
            margin: 20px auto;
        }
        .no-data {
            text-align: center;
            color: #666;
            padding: 20px;
        }
    </style>
</head>
<body>
    <h1>Статистика по расходным материалам</h1>
    <a href="index.php">Назад</a>

    <form method="get">
        <label>С:</label>
        <input type="date" name="start_date" value="<?= htmlspecialchars($start) ?>">
        <label>По:</label>
        <input type="date" name="end_date" value="<?= htmlspecialchars($end) ?>">
        <button type="submit">Применить</button>
    </form>

    <h2>Таблица</h2>
    <table border="1" cellpadding="5">
        <tr>
            <th>Материал</th>
            <th>Пришло</th>
            <th>Выдано</th>
            <th>Остаток</th>
        </tr>
        <?php foreach ($materials as $name => $_): ?>
            <tr>
                <td><?= htmlspecialchars($name) ?></td>
                <td><?= $inMap[$name] ?? 0 ?></td>
                <td><?= $outMap[$name] ?? 0 ?></td>
                <td><?= $currentData[$name] ?? 0 ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>График</h2>
    <canvas id="summaryChart" width="800" height="400"></canvas>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('summaryChart').getContext('2d');
        
        // Подготовка данных
        const labels = <?= json_encode(array_keys($materials)) ?>;
        const inData = <?= json_encode(array_values(array_map(function($m) { return $inMap[$m] ?? 0; }, array_keys($materials)))) ?>;
        const outData = <?= json_encode(array_values(array_map(function($m) { return $outMap[$m] ?? 0; }, array_keys($materials)))) ?>;
        
        console.log('Данные для графика:', {labels, inData, outData});

        if (labels.length === 0) {
            ctx.canvas.parentNode.innerHTML = '<p class="no-data">Нет данных для отображения за выбранный период</p>';
            return;
        }

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Пришло',
                        data: inData,
                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Выдано',
                        data: outData,
                        backgroundColor: 'rgba(255, 99, 132, 0.7)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Количество'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Материалы'
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${context.raw} шт.`;
                            }
                        }
                    },
                    title: {
                        display: true,
                        text: 'Приход/Расход материалов (<?= date('d.m.Y', strtotime($start)) ?> - <?= date('d.m.Y', strtotime($end)) ?>)'
                    }
                }
            }
        });
    });
    </script>
</body>
</html>
