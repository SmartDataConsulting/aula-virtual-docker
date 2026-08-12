<fieldset class="survey-question">
  <legend>
    <span class="survey-question-number">{{ $number }}</span>
    {{ $question->text }}
    @if($question->required)<span class="survey-required">Obligatoria</span>@endif
  </legend>

  @if($question->type === 'scale')
    <div class="survey-scale-labels" aria-hidden="true"><span>{{ $question->scale->min }}</span><span>{{ $question->scale->max }}</span></div>
    <div class="survey-scale-options">
      @for($value = $question->scale->min; $value <= $question->scale->max; $value++)
        <label>
          <input type="radio" name="{{ $fieldName }}" value="{{ $value }}" @checked((string) $oldValue === (string) $value) @required($question->required)>
          <span>{{ $value }}</span>
        </label>
      @endfor
    </div>
  @elseif($question->type === 'select')
    <label class="sr-only" for="{{ $fieldId }}">{{ $question->text }}</label>
    <select id="{{ $fieldId }}" name="{{ $fieldName }}" @required($question->required)>
      <option value="">Selecciona una opción</option>
      @foreach($question->options as $option)
        <option value="{{ $option }}" @selected((string) $oldValue === (string) $option)>{{ $option }}</option>
      @endforeach
    </select>
  @elseif($question->type === 'number')
    <label class="sr-only" for="{{ $fieldId }}">{{ $question->text }}</label>
    <input id="{{ $fieldId }}" type="number" name="{{ $fieldName }}" value="{{ $oldValue }}" @required($question->required)>
  @else
    <label class="sr-only" for="{{ $fieldId }}">{{ $question->text }}</label>
    <textarea id="{{ $fieldId }}" name="{{ $fieldName }}" rows="4" maxlength="5000" @required($question->required)>{{ $oldValue }}</textarea>
  @endif

  @error($errorKey)
    <p class="survey-question-error" role="alert">{{ $message }}</p>
  @enderror
</fieldset>
