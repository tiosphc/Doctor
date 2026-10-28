import { Chart, LineController, LineElement, PointElement, CategoryScale, LinearScale, Legend, Tooltip } from 'chart.js';

Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Legend, Tooltip);

const canvas = document.getElementById('report-revenue-chart');
const trend = window.adminReportTrend ?? [];

if (canvas && trend.length > 0) {
    new Chart(canvas, {
        type: 'line',
        data: {
            labels: trend.map((point) => point.date),
            datasets: [
                { label: 'Thanh toán gộp', data: trend.map((point) => Number(point.gross)), borderColor: '#c3a26c', tension: 0.25 },
                { label: 'Hoàn tiền', data: trend.map((point) => Number(point.refunds)), borderColor: '#c45b5b', tension: 0.25 },
                { label: 'Doanh thu ròng', data: trend.map((point) => Number(point.net)), borderColor: '#102548', tension: 0.25 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: { y: { ticks: { callback: (value) => new Intl.NumberFormat('vi-VN', { notation: 'compact' }).format(value) } } },
        },
    });
}
