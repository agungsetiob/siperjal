<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Profil · SIPERJAL')] class extends Component
{
};
?>

<div class="mx-auto min-h-[calc(100dvh-72px)] max-w-lg bg-bg lg:my-6 lg:min-h-0 lg:rounded-md lg:outline lg:outline-1 lg:outline-line">
    <livewire:profil :halaman="true" />
</div>
