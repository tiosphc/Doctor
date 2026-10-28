import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(
    BarController,
    BarElement,
    CategoryScale,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
);

const data = window.adminDashboardChartData;
const formatMoney = (value) => `${new Intl.NumberFormat('vi-VN').format(value)} ₫`;

function renderCharts() {
    const appointmentsCanvas = document.getElementById('appointments-chart');

    if (appointmentsCanvas && data?.appointments) {
        new Chart(appointmentsCanvas, {
            type: 'bar',
            data: {
                labels: data.appointments.map((point) => point.date.slice(5)),
                datasets: [
                    { label: 'Tổng lịch', data: data.appointments.map((point) => point.total), backgroundColor: '#102548', borderRadius: 4 },
                    { label: 'Hoàn thành', data: data.appointments.map((point) => point.completed), backgroundColor: '#3b9b70', borderRadius: 4 },
                    { label: 'Đã hủy', data: data.appointments.map((point) => point.cancelled), backgroundColor: '#d46b6b', borderRadius: 4 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
    }

    const revenueCanvas = document.getElementById('revenue-chart');

    if (revenueCanvas && data?.revenue?.length) {
        new Chart(revenueCanvas, {
            type: 'line',
            data: {
                labels: data.revenue.map((point) => point.date.slice(5)),
                datasets: [
                    { label: 'Gộp', data: data.revenue.map((point) => Number(point.gross)), borderColor: '#b58a57', tension: 0.25, pointRadius: 0 },
                    { label: 'Hoàn tiền', data: data.revenue.map((point) => Number(point.refunds)), borderColor: '#d46b6b', tension: 0.25, pointRadius: 0 },
                    { label: 'Ròng', data: data.revenue.map((point) => Number(point.net)), borderColor: '#102548', tension: 0.25, pointRadius: 0 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: { y: { ticks: { callback: (value) => new Intl.NumberFormat('vi-VN', { notation: 'compact' }).format(value) } } },
                plugins: { tooltip: { callbacks: { label: (context) => `${context.dataset.label}: ${formatMoney(context.parsed.y)}` } } },
            },
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderCharts, { once: true });
} else {
    renderCharts();
}

window.setInterval(() => {
    if (document.visibilityState === 'visible') {
        window.location.reload();
    }
}, 60_000);
