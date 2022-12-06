var chart; // global

// invoked when sending ajax request
$(document).ajaxSend(function () {
    $("#loading").show();
});

// invoked when sending ajax completed
$(document).ajaxComplete(function () {
    $("#loading").hide();
});


/**
 * Request data from the server and add it to the graph
 */
function requestData() {
    $.ajax({
        url: 'https://services.jacq.org/jacq-services/monitor/rest/wildfly/input/ready-for-chart/2022-01',
        success: function (data) {
            chart.series[0].setData(data['used']);
            chart.series[1].setData(data['committed']);
        },
        cache: false
    });
}


$(function () {
    chart = new Highcharts.stockChart({
        chart: {
            renderTo: 'container'
        },
        title: {
            text: 'Speicherbedarf Wildfly Input-Server'
        },
        xAxis: {
            type: 'datetime',
            crosshair: true,
            ordinal: false
        },
        yAxis: [{ // Primary yAxis
            opposite: false,
            title: {
                text: 'Speicherbedarf'
            },
            labels: {
                format: '{value} MiB'
            },
            min: 0
        }],
        rangeSelector: {
            buttons: [{
                type: 'day',
                count: 1,
                text: 'Day'
            }, {
                type: 'week',
                count: 1,
                text: 'Week'
            }, {
                type: 'month',
                count: 1,
                text: 'Month'
            }],
            buttonTheme: {
                width: 60
            },
            selected: 1
        },
        time: {
            useUTC: false
        },
        tooltip: {
            shared: true
        },
        legend: {
            enabled: true,
            layout: 'vertical',
            align: 'left',
            verticalAlign: 'top',
            x: 400,
            y: 10,
            floating: true
        },
        plotOptions: {
            series: {
                label: {
                    connectorAllowed: false
                }
            }
        },
        series: [{
            name: 'used',
            yAxis: 0,
            zIndex: 2,
            color: '#0000FF',
            tooltip: {
                valueSuffix: ' MiB'
            }
        }, {
            name: 'committed',
            yAxis: 0,
            zIndex: 2,
            color: '#FF0000',
            tooltip: {
                valueSuffix: ' MiB'
            },
            visible: true
        }]
    });
    requestData();
});
