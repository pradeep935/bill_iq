<template>
  <div>
    <h2>Vue 3 Pie Chart Example</h2>
    <Pie :data="data" :options="options" />
  </div>
</template>

<script setup>
import { Pie } from 'vue-chartjs'
import {
  Chart as ChartJS,
  Title,
  Tooltip,
  Legend,
  ArcElement
} from 'chart.js'

// Register required chart.js components
ChartJS.register(Title, Tooltip, Legend, ArcElement)

// Pie chart data
const data = {
  labels: ['Red', 'Blue', 'Yellow'],
  datasets: [
    {
      label: 'Votes',
      data: [30, 50, 20],
      backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56'],
      borderWidth: 1
    }
  ]
}

// Pie chart options
const options = {
  responsive: true,
  plugins: {
    legend: {
      position: 'top'
    },
    title: {
      display: true,
      text: 'Pie Chart'
    }
  }
}
</script>

<style scoped>
div {
  max-width: 400px;
  margin: 40px auto;
}
</style>
