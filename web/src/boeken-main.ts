import { runBookingPage } from './boeken'
import { fetchBookingLink } from './api'

runBookingPage({
  search: location.search,
  render: html => { document.getElementById('app')!.innerHTML = html },
  fetchLink: fetchBookingLink,
  navigate: url => location.replace(url),
})
